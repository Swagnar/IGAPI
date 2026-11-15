<?php
require_once __DIR__ . '/bootstrap.php';

// Produkcja wymaga jawnego DEBUG=false w .env — brak wpisu = tryb debug, żeby lokalny run niczego nie opublikował
define('DEBUG', filter_var($_ENV['DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN));

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Http\InstagramPublisher;

use Swagnar\Igapi\Data\DataCache;
use Swagnar\Igapi\Data\SourceStore;
use Swagnar\Igapi\Data\Sources;
use Swagnar\Igapi\Template\Playlist;
use Swagnar\Igapi\Web\WebExport;

use Swagnar\Igapi\Helper\Alert;
use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Contracts\StoryRunner;

if (DEBUG) {
    define('PUBLIC_DIR', __DIR__ . '/ig-stories');
    define('PUBLIC_URL', __DIR__ . '/ig-stories');
    // Próbny run z deploy.sh --run podaje IGAPI_WEB_DIR = document root strony, żeby eksport był widoczny na swagnar.dev
    define('WEB_DIR', getenv('IGAPI_WEB_DIR') ?: __DIR__ . '/web');
    Console::debug("DEBUG MODE: Pamiętaj aby zmienić przed wdrożeniem na produkcję.");
} else {
    // Katalog musi leżeć w document root domeny z PUBLIC_URL — Instagram pobiera obrazki po URL-u
    define('PUBLIC_DIR', $_ENV['PUBLIC_DIR'] ?? ROOT_DIR . '/../swagnar.dev/ig-stories');
    define('PUBLIC_URL', $_ENV['PUBLIC_URL'] ?? 'https://swagnar.dev/ig-stories');
    // Document root strony swagnar.dev — run zapisuje tam data/today.json i obrazki dla WWW
    define('WEB_DIR', $_ENV['WEB_DIR'] ?? ROOT_DIR . '/../swagnar.dev');
}

// Fatal (brak pamięci, limit czasu) omija try/catch — zapisujemy go w logu i wysyłamy alert z tego, co zdążyło się wykonać.
// Rezerwa pamięci zwalniana na starcie handlera (plus podniesiony limit), żeby po „Allowed memory size exhausted” dało się jeszcze wysłać mail.
$memoryReserve = str_repeat(' ', 1 << 20);
register_shutdown_function(function () use (&$memoryReserve): void {
    $memoryReserve = null;
    // Zaalokowana pamięć zostaje po fatalu — na mail z alertem dokładamy limit
    ini_set('memory_limit', (string) (memory_get_usage() + (64 << 20)));
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR], true)) {
        Console::fatal("Run przerwany: {$error['message']} ({$error['file']}:{$error['line']})");
    }
    // Lokalny run w trybie debug nie wysyła maili — chyba że ALERT_IN_DEBUG=true (np. test skrzynki)
    if (!DEBUG || filter_var($_ENV['ALERT_IN_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        Alert::sendIfProblems('run ' . date('Y-m-d H:i') . (DEBUG ? ' (debug)' : ''));
    }
});

if(!file_exists(FONT)) throw new RuntimeException("Brak fontu: " . FONT);
if(!file_exists(FONT_BOLD)) throw new RuntimeException("Brak fontu: " . FONT_BOLD);
if(!is_dir(PUBLIC_DIR) || !is_writable(PUBLIC_DIR)) throw new RuntimeException("Brak katalogu lub brak praw zapisu: " . PUBLIC_DIR);

Console::write('', Console::WHITE,"
  ██╗ ██████╗      █████╗ ██████╗ ██╗
  ██║██╔════╝     ██╔══██╗██╔══██╗██║
  ██║██║  ███╗    ███████║██████╔╝██║
  ██║██║   ██║    ██╔══██║██╔═══╝ ██║
  ██║╚██████╔╝    ██║  ██║██║     ██║
  ╚═╝ ╚═════╝     ╚═╝  ╚═╝╚═╝     ╚═╝

  Instagram Story Publisher
  ─────────────────────────────────
");
Console::boot("Inicjalizacja helperów");

$http = new JsonClient();
$publisher = new InstagramPublisher(
    accessToken: $_ENV['PAGE_ACCESS_TOKEN'],
    igUserId: $_ENV['IG_ID'],
    http: $http,
    appId: $_ENV['APP_ID'] ?? null,
    appSecret: $_ENV['APP_SECRET'] ?? null,
);

$sources = Sources::all($http);
$cache   = new DataCache(ROOT_DIR . '/cache', $http);
// Wspólne dane wszystkich slajdów i strony WWW — każde źródło pobiera się raz, dopiero gdy jest potrzebne
$store   = new SourceStore($sources, $cache);

// Kolejność i włączone slajdy: stories.json (edytowalne też w edytorze — „Plan publikacji”)
$modules = Playlist::fromFile(ROOT_DIR . '/stories.json')->modules(ROOT_DIR . '/slides', $http, $sources, $cache, $store);

// 1. Story: pobiera dane tylko wylosowanych pluginów, generuje i publikuje slajdy
Console::boot("Uruchamianie runnera");

$result = ['published' => false, 'payloads' => [], 'failed' => []];
try {
    $result = (new StoryRunner($modules, $publisher))->run();
} catch (\Throwable $e) {
    Console::error("Błąd w runnerze: " . $e->getMessage());
}

// 2. WWW: dzisiejsze story na stronie od razu po publikacji; 3. reszta pluginów — ich dane pobierają się dopiero teraz
Console::boot("Eksport na stronę WWW");

try {
    $web = new WebExport(WEB_DIR, $store);
    $web->addStory($modules, $result);
    $web->write(complete: false);
    Console::success("WWW: dzisiejsze story zapisane");

    $web->addRemaining();
    Console::success("WWW: zapisano " . $web->write(complete: true));
} catch (\Throwable $e) {
    Console::error("Błąd eksportu WWW: " . $e->getMessage());
}