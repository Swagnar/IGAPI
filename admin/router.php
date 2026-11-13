<?php
/**
 * Lokalny edytor slajdów — tylko localhost, nie trafia na serwer (deploy.sh go nie wysyła).
 *
 *   composer admin            →  http://127.0.0.1:8080
 *   php -d xdebug.mode=off -S 127.0.0.1:8080 admin/router.php
 *
 * Edytor i Claude Code w terminalu pracują na tych samych plikach (slides/*.json, cache/*.json):
 * zmiany z dysku edytor wykrywa przez /api/changes i sam odświeża podgląd.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Data\DataCache;
use Swagnar\Igapi\Data\SourceStore;
use Swagnar\Igapi\Data\Sources;
use Swagnar\Igapi\Data\Sources\PluginSource;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Http\InstagramPublisher;
use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Layout\VerticalSplit;
use Swagnar\Igapi\Template\ComponentFactory;
use Swagnar\Igapi\Template\JsonPlugins;
use Swagnar\Igapi\Template\Playlist;
use Swagnar\Igapi\Template\SlideJson;
use Swagnar\Igapi\Template\TemplateModule;

const SLIDES_DIR = ROOT_DIR . '/slides';
const STORIES    = ROOT_DIR . '/stories.json';
const CACHE_DIR  = ROOT_DIR . '/cache';
const STATIC_DIR = __DIR__ . '/public';
const STATUS_TTL = 600; // s — jak długo pamiętamy wynik sprawdzenia tokenu Meta i klucza NASA

define('DEBUG', true);
define('EDITOR', true); // m.in. BitcoinPlugin nie dopisuje cen do historii przy „Pobierz teraz”
define('PUBLIC_DIR', ROOT_DIR . '/ig-stories');
define('PUBLIC_URL', ROOT_DIR . '/ig-stories');

GdImageHelper::$remoteImageCacheDir = CACHE_DIR . '/images';

// „Pobierz teraz” dla wolnych źródeł (Energy-Charts z limitem zapytań) trwa minuty — na Windows sleep liczy się do limitu
set_time_limit(0);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!str_starts_with($path, '/api/')) {
    serveStatic($path);
    return true;
}

// Console:: pisze na stdout — w API zbieramy to jako log zamiast psuć JSON
ob_start();
try {
    [$status, $body] = route($method, substr($path, 5), $_GET);
} catch (\Throwable $e) {
    [$status, $body] = [422, ['error' => $e->getMessage()]];
}
$log = trim(preg_replace('/\e\[[\d;]*m/', '', (string) ob_get_clean()));

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (is_array($body) && !array_is_list($body) && $log !== '') {
    $body['log'] = explode("\n", $log);
}
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
return true;

// ---------------------------------------------------------------------------------------------

function route(string $method, string $path, array $query): array
{
    $parts = explode('/', trim($path, '/'));

    return match (true) {
        $method === 'GET'  && $path === 'slides'                 => [200, listSlides()],
        $method === 'POST' && $path === 'slides'                 => [201, createSlide(requestJson())],
        $method === 'GET'  && $path === 'stories'                => [200, readStories()],
        $method === 'PUT'  && $path === 'stories'                => [200, saveStories(requestJson())],
        $method === 'GET'  && $path === 'plugins'                => [200, listJsonPlugins()],
        $method === 'POST' && $path === 'plugins'                => [201, createJsonPlugin(requestJson())],
        $method === 'GET'  && $parts[0] === 'plugins' && isset($parts[1]) => [200, readJsonPlugin($parts[1])],
        $method === 'PUT'  && $parts[0] === 'plugins' && isset($parts[1]) => [200, saveJsonPlugin($parts[1], requestJson())],
        $method === 'GET'  && $parts[0] === 'slides' && isset($parts[1]) => [200, readSlide($parts[1])],
        $method === 'PUT'  && $parts[0] === 'slides' && isset($parts[1]) => [200, saveSlide($parts[1], requestJson())],
        $method === 'POST' && $path === 'preview'                => [200, preview(requestJson())],
        $method === 'POST' && $path === 'calendar'               => [200, calendar(requestJson())],
        $method === 'GET'  && $path === 'schema'                 => [200, schema()],
        $method === 'GET'  && $path === 'sources'                => [200, listSources()],
        $method === 'GET'  && $parts[0] === 'sources' && isset($parts[1]) && !isset($parts[2]) => [200, sourceEntry($parts[1])],
        $method === 'POST' && $parts[0] === 'sources' && ($parts[2] ?? '') === 'refresh' => [200, refreshSource($parts[1])],
        $method === 'GET'  && $path === 'changes'                => [200, changes()],
        $method === 'GET'  && $path === 'status'                 => [200, status(isset($query['refresh']))],
        $method === 'POST' && $path === 'selection'              => [200, saveSelection(requestJson())],
        default                                                 => [404, ['error' => "Nieznany endpoint: {$method} /api/{$path}"]],
    };
}

function listSlides(): array
{
    $slides = [];
    foreach (glob(SLIDES_DIR . '/*.json') as $file) {
        $name = basename($file, '.json');
        try {
            $definition = TemplateModule::load($file);
            $slides[]   = ['name' => $name, 'label' => $definition['label'] ?? $name, 'title' => $definition['title'] ?? null];
        } catch (\Throwable $e) {
            $slides[] = ['name' => $name, 'label' => $name, 'error' => $e->getMessage()];
        }
    }
    return $slides;
}

/** Nowy slajd: plik ze szkieletem + wpis w planie publikacji jako wyłączony (szkic nie pójdzie na Instagram). */
function createSlide(array $request): array
{
    $name = slideName(strtolower(trim($request['name'] ?? '')));
    if (Playlist::isCode($name)) {
        throw new RuntimeException("Nazwa „{$name}” jest zajęta przez moduł z kodu");
    }
    $file = SLIDES_DIR . "/{$name}.json";
    if (is_file($file)) {
        throw new RuntimeException("Slajd {$name} już istnieje");
    }

    $title = trim($request['title'] ?? '') ?: mb_strtoupper($name);
    file_put_contents($file, SlideJson::encode([
        'label'   => trim($request['label'] ?? '') ?: $title,
        'title'   => $title,
        'footer'  => 'Źródło: {{credits}}',
        'sources' => [],
        'body'    => [
            'type'     => 'Stack',
            'children' => [
                ['type' => 'Spacer', 'height' => 30],
                ['type' => 'Text', 'text' => 'Nowy slajd', 'size' => 28, 'font' => 'bold', 'height' => 40],
            ],
        ],
    ]));

    $playlist = Playlist::fromFile(STORIES);
    $playlist->add($name, enabled: false);
    $playlist->save();

    return ['name' => $name];
}

/** Plan publikacji z opisem każdego wpisu + slajdy, których w planie nie ma. */
function readStories(): array
{
    $slides  = array_column(listSlides(), null, 'name');
    $entries = [];
    foreach (Playlist::fromFile(STORIES)->entries() as $entry) {
        $id        = $entry['id'];
        $code      = Playlist::isCode($id);
        $entries[] = $entry + [
            'kind'   => $code ? 'code' : 'slide',
            'label'  => $code ? (new (Playlist::CODE_MODULES[$id])(http()))->getLabel() : ($slides[$id]['label'] ?? $id),
            'exists' => $code || isset($slides[$id]),
            'error'  => $slides[$id]['error'] ?? null,
        ];
        unset($slides[$id]);
    }

    return [
        'entries'   => $entries,
        'unplanned' => array_values(array_map(fn($s) => ['id' => $s['name'], 'label' => $s['label']], $slides)),
    ];
}

function saveStories(array $request): array
{
    $playlist = Playlist::fromFile(STORIES);
    $playlist->replace($request['entries'] ?? throw new RuntimeException('Brak pola entries'));
    $playlist->save();
    return readStories();
}

function readSlide(string $name): array
{
    $file = slideFile($name);
    return ['name' => $name, 'definition' => TemplateModule::load($file), 'mtime' => filemtime($file)];
}

function saveSlide(string $name, array $request): array
{
    $definition = $request['definition'] ?? throw new RuntimeException('Brak pola definition');
    if (!isset($definition['body']['type'])) {
        throw new RuntimeException('Szablon musi mieć body z polem type');
    }

    $file = slideFile($name);
    file_put_contents($file, SlideJson::encode($definition));
    clearstatcache(true, $file);

    return ['name' => $name, 'mtime' => filemtime($file)];
}

function preview(array $request): array
{
    $definition = $request['definition'] ?? throw new RuntimeException('Brak pola definition');
    $name       = slideName($request['name'] ?? 'preview');
    $isPlugin   = ($request['kind'] ?? 'slides') === 'plugins';
    $force      = $request['force'] ?? null;

    $skipped = null;
    if ($isPlugin) {
        // Dzień, w którym plugin zwrócił skip() (np. brak przelotu ISS): bloki nie mają danych, więc zamiast
        // błędu „null given” pokazujemy w ich miejscu powód — na slajdzie w tym miejscu losuje się inny blok
        foreach ($definition['sources'] ?? [] as $id) {
            $skipped ??= AbstractPlugin::skipReason(cache()->entry((string) $id)['data'] ?? null);
        }
        if ($skipped !== null) {
            $definition['body'] = ['type' => 'Stack', 'children' => [
                ['type' => 'Spacer', 'height' => 120],
                ['type' => 'Text', 'text' => 'Plugin dziś pominięty', 'size' => 34, 'font' => 'bold', 'height' => 60],
                ['type' => 'WrappedText', 'text' => $skipped, 'size' => 22, 'color' => '#8C9AB8', 'lineHeight' => 30],
                ['type' => 'Spacer', 'height' => 20],
                ['type' => 'WrappedText', 'text' => 'Na slajdzie w kontenerze z pick w jego miejsce losuje się inny blok. Bloki można edytować w drzewie — podgląd wróci, gdy będą dane.', 'size' => 18, 'color' => '#5B6B8C', 'lineHeight' => 26],
            ]];
        }

        // Plugin pokazujemy w jego rozmiarze ("size" w ćwiartkach slajdu, bez pola = 2/4; "{{…}}" = rozmiar z dzisiejszych
        // danych, np. curiosity zależnie od proporcji zdjęcia) — tyle miejsca dostaje na story.
        // Reszta slajdu to wypełniacz bez rozmiaru: VerticalSplit oddaje mu pozostałe ćwiartki.
        $node     = ['type' => ComponentFactory::PLUGIN, 'plugin' => $name];
        $size     = (new ComponentFactory(new SourceStore(sources(), cache(), useCache: true), sources(), plugins: JsonPlugins::collect($node)))
            ->pluginSize($definition);
        $children = [($definition['body'] ?? throw new RuntimeException('Plugin bez pola body')) + ['quarters' => $size]];
        if ($size < VerticalSplit::QUARTERS) {
            $children[] = ['type' => 'Stack', 'children' => [
                ['type' => 'Separator', 'marginTop' => 0, 'marginBottom' => 30, 'color' => '#22304A'],
                ['type' => 'Text', 'text' => 'pozostałe ' . (VerticalSplit::QUARTERS - $size) . '/4 slajdu', 'size' => 22, 'color' => '#2E3B58', 'align' => 'center', 'height' => 40],
            ]];
        }
        $definition = [
            'title'   => mb_strtoupper($definition['label'] ?? $name),
            'footer'  => "Podgląd pluginu plugins/{$name}.json — {$size}/4 slajdu",
            'sources' => $definition['sources'] ?? [],
            'body'    => ['type' => 'VerticalSplit', 'gap' => 40, 'children' => $children],
        ];
        $force = $force === null ? null : pluginPath($force, toSlide: true);
    }

    $started = microtime(true);
    $module  = new TemplateModule($name, $definition, sources(), cache(), useCache: true);
    try {
        $result = $module->preview($force);
    } catch (RuntimeException $e) {
        throw $isPlugin ? new RuntimeException(pluginPath($e->getMessage(), toSlide: false), 0, $e) : $e;
    }

    $boxes = $result['boxes'];
    if ($isPlugin) {
        // Ścieżki z opakowania (body.children[0]…) z powrotem na ścieżki w pliku pluginu (body…)
        $boxes = array_values(array_filter(array_map(function (array $b) {
            if (!preg_match('/^body\.children\[0\](.*)$/', $b['path'], $m)) {
                return null;
            }
            return ['path' => 'body' . $m[1]] + $b;
        }, $boxes)));
    }

    return [
        'png'     => base64_encode($result['png']),
        'boxes'   => $skipped === null ? $boxes : [],
        'ms'      => (int) round((microtime(true) - $started) * 1000),
        'skipped' => $skipped,
    ];
}

/**
 * Kalendarz pul slajdu: co 'pick' wylosuje w najbliższych dniach — z niezapisanej definicji z edytora.
 * Przyszłe dni liczone są na dzisiejszym cache, więc plugin z canSkip() może danego dnia jednak wypaść.
 * @return array{days: array<string, array<string, list<string>>>, sizes: array<string, int>}
 *         data → ścieżka puli → id pluginu albo numer dziecka; rozmiary pluginów JSON w ćwiartkach
 */
function calendar(array $request): array
{
    $definition = $request['definition'] ?? throw new RuntimeException('Brak pola definition');
    $days       = max(1, min(14, (int) ($request['days'] ?? 7)));
    $module     = new TemplateModule(slideName($request['name'] ?? 'preview'), $definition, sources(), cache(), useCache: true);
    $calendar   = $module->calendar($days);

    // Rozmiar wylosowanych pluginów JSON w ćwiartkach — z dzisiejszych danych (curiosity w inne dni może mieć inny)
    $factory = new ComponentFactory(new SourceStore(sources(), cache(), useCache: true), sources());
    $sizes   = [];
    foreach ($calendar as $pools) {
        foreach ($pools as $blocks) {
            foreach ($blocks as $id) {
                if (!ctype_digit($id) && !isset($sizes[$id])) {
                    $sizes[$id] = $factory->pluginSize(JsonPlugins::load($id));
                }
            }
        }
    }
    return ['days' => $calendar, 'sizes' => $sizes];
}

/** body… w pliku pluginu ↔ body.children[0]… w opakowaniu podglądu (także na początku komunikatu błędu). */
function pluginPath(string $text, bool $toSlide): string
{
    return $toSlide
        ? preg_replace('/^body/', 'body.children[0]', $text)
        : preg_replace('/^body\.children\[0\]/', 'body', $text);
}

function listJsonPlugins(): array
{
    $list = [];
    foreach (JsonPlugins::ids() as $id) {
        try {
            $definition = JsonPlugins::load($id);
            $list[]     = ['name' => $id, 'label' => $definition['label'] ?? $id, 'sources' => $definition['sources'] ?? [], 'enabled' => JsonPlugins::enabled($definition), 'size' => $definition['size'] ?? null];
        } catch (\Throwable $e) {
            $list[] = ['name' => $id, 'label' => $id, 'error' => $e->getMessage()];
        }
    }
    return $list;
}

function readJsonPlugin(string $id): array
{
    $file = JsonPlugins::file($id);
    if (!is_file($file)) {
        throw new RuntimeException("Brak pluginu plugins/{$id}.json");
    }
    $definition = json_decode((string) file_get_contents($file), true);
    if (!is_array($definition)) {
        throw new RuntimeException("plugins/{$id}.json: nieprawidłowy JSON (" . json_last_error_msg() . ')');
    }
    return ['name' => $id, 'definition' => $definition, 'mtime' => filemtime($file)];
}

function saveJsonPlugin(string $id, array $request): array
{
    $definition = $request['definition'] ?? throw new RuntimeException('Brak pola definition');
    if (!isset($definition['body']['type'])) {
        throw new RuntimeException('Plugin musi mieć body z polem type');
    }
    $file = JsonPlugins::file($id);
    if (!is_file($file)) {
        throw new RuntimeException("Brak pluginu plugins/{$id}.json");
    }
    file_put_contents($file, SlideJson::encode($definition));
    clearstatcache(true, $file);
    return ['name' => $id, 'mtime' => filemtime($file)];
}

/** Nowy plugin JSON ze szkieletem w stylu pozostałych pluginów (etykieta 24 px + wartość 48 px). */
function createJsonPlugin(array $request): array
{
    $id   = trim($request['name'] ?? '');
    $file = JsonPlugins::file($id);
    if (is_file($file)) {
        throw new RuntimeException("Plugin {$id} już istnieje");
    }
    if (!is_dir(JsonPlugins::dir())) {
        mkdir(JsonPlugins::dir(), 0775, true);
    }
    $label = trim($request['title'] ?? '') ?: $id;
    file_put_contents($file, SlideJson::encode([
        'label'   => $label,
        'sources' => [],
        'body'    => [
            'type'     => 'Stack',
            'name'     => $label,
            'children' => [
                ['type' => 'Spacer', 'height' => 30],
                ['type' => 'Text', 'text' => $label, 'size' => 24, 'color' => '#8899CC', 'height' => 40],
                ['type' => 'Text', 'text' => '42', 'size' => 48, 'font' => 'bold', 'height' => 70],
            ],
        ],
    ]));
    return ['name' => $id];
}

function schema(): array
{
    $sources = [];
    foreach (sources() as $id => $source) {
        $sources[] = [
            'id'      => $id,
            'label'   => $source->label(),
            'plugin'  => $source instanceof PluginSource,
            'canSkip' => $source instanceof PluginSource && $source->plugin()->canSkip(),
        ];
    }
    return ['components' => ComponentFactory::schema(), 'sources' => $sources];
}

/** Ostatni wpis z cache (dane, metadane, nagrane zapytania HTTP); pobiera ze źródła, jeśli cache jeszcze nie ma. */
function sourceEntry(string $id): array
{
    $source = sources()[$id] ?? throw new RuntimeException("Nieznane źródło: {$id}");

    if (cache()->entry($id) === null) {
        cache()->fresh($source);
    }
    $entry = cache()->entry($id) ?? ['source' => $id, 'ok' => false, 'error' => 'Brak danych'];
    // Panel danych pokazuje też pola liczone przez present() pluginu — tak jak widzi je szablon
    if (is_array($entry['data'] ?? null)) {
        $entry['data'] = TemplateModule::present($source, $entry['data']);
    }
    return $entry + ['usedBy' => usedBy()[$id] ?? []];
}

function refreshSource(string $id): array
{
    $source = sources()[$id] ?? throw new RuntimeException("Nieznane źródło: {$id}");
    cache()->fresh($source);
    return sourceEntry($id);
}

/** Podsumowanie wszystkich źródeł — bez danych i odpowiedzi, żeby lista była lekka. */
function listSources(): array
{
    $list = [];
    foreach (sources() as $id => $source) {
        $entry  = cache()->entry($id) ?? [];
        $list[] = [
            'id'             => $id,
            'label'          => $source->label(),
            'plugin'         => $source instanceof PluginSource,
            'describe'       => $source->describe(),
            'maxFallbackAge' => $source->maxFallbackAge(),
            'cached'         => $entry !== [],
            'ok'             => $entry['ok'] ?? null,
            'error'          => $entry['error'] ?? null,
            'fetchedAt'      => $entry['fetchedAt'] ?? null,
            'attemptedAt'    => $entry['attemptedAt'] ?? $entry['fetchedAt'] ?? null,
            'durationMs'     => $entry['durationMs'] ?? null,
            'requests'       => count($entry['requests'] ?? []),
            'usedBy'         => usedBy()[$id] ?? [],
        ];
    }
    return $list;
}

/** @return array<string, list<string>> id źródła → slajdy i pluginy JSON, które go używają */
function usedBy(): array
{
    $used = [];
    foreach (glob(SLIDES_DIR . '/*.json') as $file) {
        try {
            foreach (TemplateModule::load($file)['sources'] ?? [] as $id) {
                $used[$id][] = basename($file, '.json');
            }
        } catch (\Throwable) {
            // zepsuty szablon pokaże się na liście slajdów — tu go pomijamy
        }
    }
    foreach (JsonPlugins::ids() as $plugin) {
        try {
            foreach (JsonPlugins::load($plugin)['sources'] ?? [] as $id) {
                $used[$id][] = "plugin {$plugin}";
            }
        } catch (\Throwable) {
            // jak wyżej
        }
    }
    return $used;
}

/**
 * Zaznaczenie z edytora → .igapi/selection.json. Claude Code w terminalu czyta ten plik,
 * gdy prosisz o zmianę „zaznaczonego bloku”.
 */
function saveSelection(array $selection): array
{
    $dir = ROOT_DIR . '/.igapi';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $selection = ['selectedAt' => date(DATE_ATOM)] + $selection;
    file_put_contents($dir . '/selection.json', json_encode($selection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return ['ok' => true];
}

function changes(): array
{
    clearstatcache();
    $mtimes = fn(string $pattern) => array_combine(
        array_map(fn($f) => basename($f, '.json'), $files = glob($pattern)),
        array_map('filemtime', $files),
    );
    return [
        'slides'  => $mtimes(SLIDES_DIR . '/*.json'),
        'plugins' => $mtimes(JsonPlugins::dir() . '/*.json'),
        'cache'   => $mtimes(CACHE_DIR . '/*.json'),
        'stories' => is_file(STORIES) ? filemtime(STORIES) : null,
    ];
}

/**
 * Pasek statusu edytora. Sprawdzenia sieciowe (token Meta, klucz NASA) trzymamy w .igapi/status.json
 * przez STATUS_TTL, żeby co minutę nie pytać API — ?refresh wymusza nowe sprawdzenie.
 * Reszta (cache źródeł, plan, tryb, ostatni render) jest liczona z dysku przy każdym zapytaniu.
 */
function status(bool $refresh): array
{
    $file   = ROOT_DIR . '/.igapi/status.json';
    $remote = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    if ($refresh || !is_array($remote) || time() - strtotime($remote['checkedAt'] ?? '') > STATUS_TTL) {
        $remote = ['checkedAt' => date(DATE_ATOM), 'meta' => metaStatus(), 'nasa' => nasaStatus()];
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode($remote, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    return $remote + [
        'mode'    => filter_var($_ENV['DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'debug' : 'production',
        'sources' => sourcesStatus(),
        'plan'    => planStatus(),
        'render'  => lastRender(),
        'xdebug'  => xdebugMode(),
        'deploy'  => lastDeploy(),
        'commit'  => lastCommit(),
    ];
}

/** Ostatni deploy — ślad, który deploy.sh zostawia w .igapi/last-deploy.json. */
function lastDeploy(): ?array
{
    $file = ROOT_DIR . '/.igapi/last-deploy.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : null;
}

/** Ostatni commit i liczba niezacommitowanych zmian (bez gita — null). */
function lastCommit(): ?array
{
    $root = escapeshellarg(ROOT_DIR);
    $log  = trim((string) @shell_exec("git -C {$root} log -1 --format=%h%x1f%ct%x1f%s 2>" . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
    if (substr_count($log, "\x1f") !== 2) {
        return null;
    }
    [$hash, $at, $subject] = explode("\x1f", $log);
    $changes = (string) @shell_exec("git -C {$root} status --porcelain 2>" . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    return ['hash' => $hash, 'at' => (int) $at, 'subject' => $subject, 'dirty' => count(array_filter(explode("\n", $changes)))];
}

/** Tryb Xdebuga, gdy działa (np. „develop” z php.ini XAMPP-a) — rysowanie GD jest wtedy kilkanaście razy wolniejsze. */
function xdebugMode(): ?string
{
    if (!extension_loaded('xdebug')) {
        return null;
    }
    $mode = (string) ini_get('xdebug.mode');
    return $mode === '' || $mode === 'off' ? null : $mode;
}

function metaStatus(): array
{
    if (empty($_ENV['PAGE_ACCESS_TOKEN'])) {
        return ['valid' => false, 'message' => 'Brak PAGE_ACCESS_TOKEN w .env'];
    }
    $publisher = new InstagramPublisher(
        accessToken: $_ENV['PAGE_ACCESS_TOKEN'],
        igUserId: $_ENV['IG_ID'] ?? '',
        http: http(),
        appId: $_ENV['APP_ID'] ?? null,
        appSecret: $_ENV['APP_SECRET'] ?? null,
    );
    // Console:: z checkTokenStatus nie ma tu odbiorcy — połykamy, żeby nie trafił do JSON-a
    ob_start();
    try {
        return $publisher->checkTokenStatus() + ['canExpire' => !empty($_ENV['APP_ID']) && !empty($_ENV['APP_SECRET'])];
    } finally {
        ob_end_clean();
    }
}

/** Klucz NASA: ważność i limit zapytań z nagłówków X-RateLimit-* (najtańszy endpoint, jaki znamy). */
function nasaStatus(): array
{
    $key = $_ENV['NASA_API_KEY'] ?? '';
    if ($key === '') {
        return ['valid' => false, 'message' => 'Brak NASA_API_KEY w .env'];
    }

    $headers = [];
    $ch      = curl_init('https://api.nasa.gov/neo/rest/v1/stats?' . http_build_query(['api_key' => $key]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers) {
            if (str_contains($line, ':')) {
                [$name, $value]                   = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['valid' => false, 'message' => "Błąd połączenia: {$error}"];
    }
    $limit = isset($headers['x-ratelimit-limit']) ? (int) $headers['x-ratelimit-limit'] : null;
    $left  = isset($headers['x-ratelimit-remaining']) ? (int) $headers['x-ratelimit-remaining'] : null;

    return [
        'valid'   => $code === 200,
        'message' => $code === 200 ? null : (json_decode((string) $response, true)['error']['message'] ?? "HTTP {$code}"),
        'demoKey' => $key === 'DEMO_KEY',
        'limit'   => $limit,
        'left'    => $left,
    ];
}

function sourcesStatus(): array
{
    $list = listSources();
    $ok = $failed = $stale = [];
    foreach ($list as $s) {
        $age = $s['fetchedAt'] ? time() - strtotime($s['fetchedAt']) : null;
        if ($s['ok'] === false) {
            $failed[] = $s['label'];
        } elseif ($s['maxFallbackAge'] && $age !== null && $age > $s['maxFallbackAge']) {
            $stale[] = $s['label'];
        } elseif ($s['cached']) {
            $ok[] = $s['label'];
        }
    }
    $fetched = array_filter(array_column($list, 'fetchedAt'));

    return [
        'total'  => count($list),
        'ok'     => count($ok),
        'failed' => $failed,
        'stale'  => $stale,
        'oldest' => $fetched ? min(array_map('strtotime', $fetched)) : null,
    ];
}

function planStatus(): array
{
    $entries = readStories()['entries'];
    return [
        'enabled' => count(array_filter($entries, fn($e) => $e['enabled'] ?? true)),
        'total'   => count($entries),
        'broken'  => array_values(array_map(fn($e) => $e['label'], array_filter($entries, fn($e) => !$e['exists'] || $e['error']))),
    ];
}

/** Ostatni obraz wygenerowany przez run.php w trybie debug (lokalny katalog ig-stories). */
function lastRender(): ?array
{
    $files = glob(PUBLIC_DIR . '/*.png') ?: [];
    if (!$files) {
        return null;
    }
    $mtimes = array_map('filemtime', $files);
    $latest = array_keys($mtimes, max($mtimes))[0];
    return ['file' => basename($files[$latest]), 'at' => max($mtimes), 'count' => count($files)];
}

function sources(): array
{
    static $sources = null;
    return $sources ??= Sources::all(http());
}

function http(): JsonClient
{
    static $http = null;
    return $http ??= new JsonClient();
}

function cache(): DataCache
{
    static $cache = null;
    return $cache ??= new DataCache(CACHE_DIR, http());
}

function slideName(string $name): string
{
    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
        throw new RuntimeException("Nieprawidłowa nazwa slajdu: {$name}");
    }
    return $name;
}

function slideFile(string $name): string
{
    $file = SLIDES_DIR . '/' . slideName($name) . '.json';
    if (!is_file($file)) {
        throw new RuntimeException("Brak slajdu: {$name}");
    }
    return $file;
}

function requestJson(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('Nieprawidłowy JSON w zapytaniu: ' . json_last_error_msg());
    }
    return $data;
}

function serveStatic(string $path): void
{
    $file = realpath(STATIC_DIR . ($path === '/' ? '/index.html' : $path));
    if ($file === false || !str_starts_with($file, realpath(STATIC_DIR)) || !is_file($file)) {
        http_response_code(404);
        echo 'Nie znaleziono';
        return;
    }

    $types = ['html' => 'text/html', 'js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream') . '; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($file);
}
