<?php
namespace Swagnar\Igapi\Template;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Data\DataSource;
use Swagnar\Igapi\Data\SourceStore;
use Swagnar\Igapi\Data\Sources\PluginSource;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Layout\ArcticGlobe;
use Swagnar\Igapi\Layout\BarChart;
use Swagnar\Igapi\Layout\ColumnChart;
use Swagnar\Igapi\Layout\Columns;
use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Card;
use Swagnar\Igapi\Layout\Custom;
use Swagnar\Igapi\Layout\Gauge;
use Swagnar\Igapi\Layout\HeatMap;
use Swagnar\Igapi\Layout\LineChart;
use Swagnar\Igapi\Layout\MoonBar;
use Swagnar\Igapi\Layout\Photo;
use Swagnar\Igapi\Layout\Separator;
use Swagnar\Igapi\Layout\SolarSystem;
use Swagnar\Igapi\Layout\Spacer;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\SteamList;
use Swagnar\Igapi\Layout\Table;
use Swagnar\Igapi\Layout\Text;
use Swagnar\Igapi\Layout\VerticalSplit;
use Swagnar\Igapi\Layout\WorldMap;
use Swagnar\Igapi\Layout\WrappedText;
use GdImage;
use RuntimeException;

/**
 * Buduje drzewo komponentów z węzłów JSON:
 *
 *   { "type": "Text", "text": "{{asteroids.header}}", "size": 28, "font": "bold", "when": "asteroids" }
 *
 * - type     — klucz z TYPES; pozostałe pola to nazwane argumenty konstruktora klasy
 * - children — dzieci kontenera (Stack, VerticalSplit)
 * - when     — ścieżka w danych; pusta/brak → węzeł pomijany (np. gdy API zawiodło)
 * - order    — 'daily' w kontenerze: kolejność dzieci losowana raz na dzień
 * - pick     — N w kontenerze: dziennie losowane N dzieci (np. 2 z puli pluginów ŚWIATA); blok bez danych
 *              (niespełnione when, plugin zwrócił skip()) nie jest losowany — jego miejsce zajmuje kolejny.
 *              Dane kandydatów pobierane są po kolei, tylko do skompletowania N — reszta puli nie pyta API
 * - Plugin   — { "type": "Plugin", "source": "bitcoin" } rysuje plugin PHP jego własnym layoutem,
 *              { "type": "Plugin", "plugin": "asteroids" } wstawia plugin JSON z plugins/asteroids.json
 * - variants — { "by": "weather.medianTemp", "ranges": [{ "from": 30, "texts": [...] }, …, { "texts": [...] }] }:
 *              {{variant}} w tekstach węzła = jeden z tekstów zakresu o największym from ≤ wartość (zakres bez
 *              from = poniżej wszystkich); tekst losowany raz na dzień, więc podgląd się nie zmienia
 * - {{a.b}}  — wartość z danych; samodzielny placeholder bez filtrów zachowuje typ (liczba, null)
 * - filtry   — {{a.b|number:2}} 4 253,10 · {{a.b|date:d.m.Y}} · {{a.b|upper}} · {{a.b|lower}} · {{a.b|default:—}}
 * - font     — 'regular' | 'bold'
 */
final class ComponentFactory
{
    public const TYPES = [
        'Stack'         => Stack::class,
        'VerticalSplit' => VerticalSplit::class,
        'Text'          => Text::class,
        'WrappedText'   => WrappedText::class,
        'Photo'         => Photo::class,
        'Separator'     => Separator::class,
        'Spacer'        => Spacer::class,
        'MoonBar'       => MoonBar::class,
        'SolarSystem'   => SolarSystem::class,
        'WorldMap'      => WorldMap::class,
        'Table'         => Table::class,
        'Columns'       => Columns::class,
        'LineChart'     => LineChart::class,
        'BarChart'      => BarChart::class,
        'ColumnChart'   => ColumnChart::class,
        'Gauge'         => Gauge::class,
        'ArcticGlobe'   => ArcticGlobe::class,
        'HeatMap'       => HeatMap::class,
        'SteamList'     => SteamList::class,
    ];

    private const CONTAINERS = ['Stack', 'VerticalSplit', 'Columns'];

    public const PLUGIN = 'Plugin';

    private const DEFAULTS = [
        'Text'        => ['size' => 24, 'color' => '#FFFFFF', 'font' => 'regular'],
        'WrappedText' => ['size' => 22, 'color' => '#FFFFFF', 'font' => 'regular'],
    ];

    private const FONT_PROPS = ['font', 'captionFont'];

    private const META_KEYS = ['type', 'children', 'when', 'order', 'pick', 'id', 'name', 'variants', 'quarters', 'weight'];

    /** Rozmiar pluginu bez pola "size": połowa treści slajdu */
    public const DEFAULT_SIZE = 2;

    /**
     * @param SourceStore               $data    dane źródeł (pobierane przy pierwszym użyciu)
     * @param array<string, DataSource> $sources potrzebne tylko dla węzłów Plugin
     * @param ?\ArrayObject             $boxes   podane → każdy węzeł zapisuje, gdzie się narysował (edytor)
     * @param ?string                   $force   ścieżka węzła, który ma się narysować mimo dziennego 'pick' (podgląd w edytorze)
     * @param array<string, array>      $plugins definicje pluginów JSON użytych w slajdzie (JsonPlugins::collect)
     * @param ?\ArrayObject             $used    id źródeł, których dane faktycznie trafiły na slajd — wspólne z pluginami JSON
     * @param ?\ArrayObject             $drawn   id pluginów JSON, które się dziś narysowały — wspólne z pluginami w pluginach
     * @param array<string, array<string, int>> $recent pula → blok → ile dni temu wylosowany (PickHistory::recent)
     * @param ?string                   $date    dzień losowania 'Y-m-d' (domyślnie dziś) — kalendarz puli liczy kolejne dni
     */
    public function __construct(
        private readonly SourceStore $data,
        private readonly array $sources = [],
        private readonly ?\ArrayObject $boxes = null,
        private readonly ?string $force = null,
        private readonly array $plugins = [],
        private readonly int $depth = 0,
        ?\ArrayObject $used = null,
        ?\ArrayObject $drawn = null,
        private readonly array $recent = [],
        ?string $date = null,
    ) {
        $this->used   = $used ?? new \ArrayObject();
        $this->drawn  = $drawn ?? new \ArrayObject();
        $this->sizes  = new \WeakMap();
        $this->date   = $date ?? date('Y-m-d');
        $this->picked = new \ArrayObject();
    }

    private readonly string $date;

    /** @var \ArrayObject<string, list<string>> pula → wylosowane dziś bloki (id pluginu albo numer dziecka) */
    private readonly \ArrayObject $picked;

    /** @return array<string, list<string>> co wylosowały pule slajdu — do PickHistory */
    public function picked(): array
    {
        return $this->picked->getArrayCopy();
    }

    /** @var \WeakMap<Component, int> rozmiar zbudowanych bloków w ćwiartkach slajdu */
    private \WeakMap $sizes;

    private readonly \ArrayObject $used;
    private readonly \ArrayObject $drawn;

    /** @return list<string> pluginy JSON narysowane w tym drzewie, w kolejności rysowania */
    public function drawn(): array
    {
        return array_keys($this->drawn->getArrayCopy());
    }

    /**
     * Dostawcy danych narysowanych bloków do stopki ({{credits}}): „NASA / Launch Library 2”, w kolejności
     * na slajdzie, bez powtórzeń. Liczy się tylko to, co weszło do tekstów i parametrów zbudowanych bloków —
     * niewylosowany (pick), pominięty (when, skip) i wyłączony plugin nie dokłada swojego źródła.
     */
    public function credits(): string
    {
        $parts = [];
        foreach (array_keys($this->used->getArrayCopy()) as $id) {
            foreach (explode(' / ', (string) ($this->sources[$id] ?? null)?->credit()) as $part) {
                if (trim($part) !== '') {
                    $parts[trim($part)] = true;
                }
            }
        }
        return implode(' / ', array_keys($parts));
    }

    /**
     * @param ?int $capacity ile ćwiartek slajdu ma węzeł do dyspozycji — kontener z 'pick' losuje tylko bloki,
     *                       które się w tym zmieszczą; treść slajdu (body) ma 4
     */
    public function build(array $node, string $path = 'body', ?int $capacity = null): ?Component
    {
        if ($path === 'body' && $this->depth === 0) {
            $capacity ??= VerticalSplit::QUARTERS;
        }
        $component = $this->create($node, $path, $capacity);
        $size      = $component !== null ? ($this->sizes[$component] ?? null) : null;

        if ($component !== null && $this->boxes !== null) {
            $component = new Tracked($component, $path, $this->boxes);
            if ($size !== null) {
                $this->sizes[$component] = $size;
            }
        }
        return $component;
    }

    /** Rozmiar zbudowanego bloku w ćwiartkach slajdu (1–4) albo null, gdy blok nie ma rozmiaru. */
    public function sizeOf(Component $component): ?int
    {
        return $this->sizes[$component] ?? null;
    }

    private function create(array $node, string $path, ?int $capacity = null): ?Component
    {
        if (isset($node['when']) && !$this->truthy($this->lookup($node['when']))) {
            return null;
        }

        $type  = $node['type'] ?? throw new RuntimeException("{$path}: brak pola 'type'");
        if ($type === self::PLUGIN) {
            $plugin = isset($node['plugin']) ? $this->jsonPlugin($node, $path) : $this->plugin($node, $path);
            if ($plugin !== null) {
                $this->sizes[$plugin] = $this->staticSize($node);
            }
            return $plugin;
        }
        $class = self::TYPES[$type] ?? throw new RuntimeException("{$path}: nieznany typ '{$type}'");

        $props = array_diff_key($node, array_flip(self::META_KEYS)) + (self::DEFAULTS[$type] ?? []);
        if (isset($node['variants'])) {
            $variant = $this->variant($node['variants']);
            $props   = array_map(fn($v) => is_string($v) ? str_replace('{{variant}}', $variant, $v) : $v, $props);
        }
        try {
            $props = array_map(fn($value) => $this->interpolate($value), $props);
        } catch (RuntimeException $e) {
            throw new RuntimeException("{$path} ({$type}): {$e->getMessage()}", 0, $e);
        }
        foreach (self::FONT_PROPS as $key) {
            if (isset($props[$key])) {
                $props[$key] = $this->font($props[$key], $path);
            }
        }

        try {
            $component = new $class(...$props);
        } catch (\Error $e) {
            throw new RuntimeException("{$path} ({$type}): {$e->getMessage()}", 0, $e);
        }

        if (in_array($type, self::CONTAINERS, true)) {
            $children   = $node['children'] ?? [];
            $pick       = $this->pickCount($node['pick'] ?? null);
            $capacities = $pick === null ? $this->capacities($type, $children, $capacity) : [];
            $sizes      = [];
            foreach ($this->ordered($children, $node['order'] ?? null, $pick, $path, $pick === null ? null : $capacity) as $i => $child) {
                $built = $this->build($child, "{$path}.children[{$i}]", $capacities[$i] ?? null);
                if ($built === null) {
                    continue;
                }
                $size = $this->sizes[$built] ?? null;
                $component instanceof VerticalSplit ? $component->add($built, $size) : $component->add($built);
                if ($size !== null) {
                    $sizes[] = $size;
                }
            }
            // Kontener zajmuje tyle, co jego dzieci: pod sobą — suma, obok siebie (Columns) — największe
            $size = isset($node['quarters']) ? (int) $node['quarters'] : ($sizes ? ($type === 'Columns' ? max($sizes) : array_sum($sizes)) : null);
            if ($size !== null) {
                $this->sizes[$component] = min(VerticalSplit::QUARTERS, $size);
            }
            // Treść slajdu dzieli się na 4 ćwiartki (nieużyte zostają puste), zagnieżdżony podział — na tyle, ile zajmuje
            if ($component instanceof VerticalSplit && $size !== null && !($path === 'body' && $this->depth === 0)) {
                $component->quarters($size);
            }
        } elseif (isset($node['quarters'])) {
            $this->sizes[$component] = (int) $node['quarters'];
        }

        return $component;
    }

    /**
     * Opis typów dla edytora: parametry konstruktora (nazwa, typ, wartość domyślna) z refleksji.
     * @return array<string, array{container: bool, params: list<array>}>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::TYPES as $type => $class) {
            $params = [];
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
                $default  = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                $params[] = [
                    'name'     => $param->getName(),
                    'type'     => in_array($param->getName(), self::FONT_PROPS, true) ? 'font' : (string) $param->getType(),
                    'required' => !$param->isDefaultValueAvailable() && !isset(self::DEFAULTS[$type][$param->getName()]),
                    'default'  => self::DEFAULTS[$type][$param->getName()] ?? (is_scalar($default) || $default === null ? $default : null),
                ];
            }
            $schema[$type] = ['container' => in_array($type, self::CONTAINERS, true), 'params' => $params];
        }
        $schema[self::PLUGIN] = [
            'container' => false,
            'params'    => [
                ['name' => 'plugin', 'type' => 'jsonPlugin', 'required' => false, 'default' => null],
                ['name' => 'source', 'type' => 'source', 'required' => false, 'default' => null],
            ],
        ];
        return $schema;
    }

    /** Podmienia {{ścieżka}} w tekstach (także w zagnieżdżonych tablicach). */
    public function interpolate(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn($v) => $this->interpolate($v), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('/^' . self::PLACEHOLDER . '$/', $value, $m)) {
            return ($m[2] ?? '') === '' ? $this->resolve($m[1]) : $this->filter($this->resolve($m[1]), $m[2]);
        }

        return preg_replace_callback(
            '/' . self::PLACEHOLDER . '/',
            fn($m) => (string) $this->filter($this->resolve($m[1]), $m[2] ?? ''),
            $value,
        );
    }

    /**
     * Tekst dla {{variant}}: zakres o największym `from` nie większym od wartości `by` (bez `from` — gdy wartość
     * jest poniżej wszystkich albo jej brak), z niego jeden tekst — wybór stały przez dzień (seed z daty i `by`, więc podgląd pluginu i slajd losują to samo).
     */
    private function variant(array $variants): string
    {
        $value = $this->resolve((string) ($variants['by'] ?? ''));
        $best  = null;
        foreach ($variants['ranges'] ?? [] as $range) {
            $from = $range['from'] ?? null;
            if ($from === null || $from === '') {
                $best ??= $range;
                continue;
            }
            if (is_numeric($value) && (float) $value >= (float) $from
                && ($best === null || ($best['from'] ?? null) === null || (float) $from > (float) $best['from'])) {
                $best = $range;
            }
        }
        $texts = array_values(array_filter($best['texts'] ?? [], fn($t) => trim((string) $t) !== ''));
        return $texts ? (string) $texts[crc32(date('Ymd') . ($variants['by'] ?? '')) % count($texts)] : '';
    }

    /** lookup() dla placeholdera, który trafia na slajd — niepusta wartość zalicza źródło do stopki. */
    private function resolve(string $path): mixed
    {
        $value = $this->lookup($path);
        if ($value !== null && $value !== '' && $value !== []) {
            $this->used[explode('.', $path)[0]] = true;
        }
        return $value;
    }

    /** {{ścieżka}} albo {{ścieżka|filtr:arg|filtr}} */
    private const PLACEHOLDER = '\{\{\s*([\w.]+)\s*((?:\|[^|}]+)*)\s*\}\}';

    private function filter(mixed $value, string $filters): mixed
    {
        foreach (array_filter(explode('|', $filters), fn($f) => trim($f) !== '') as $filter) {
            [$name, $arg] = array_map('trim', explode(':', $filter, 2)) + [1 => null];

            $value = match ($name) {
                'number'  => is_numeric($value) ? number_format((float) $value, (int) ($arg ?? 0), ',', ' ') : $value,
                'date'    => $value === null || $value === '' ? $value
                    : date($arg ?? 'd.m.Y', is_numeric($value) ? (int) $value : (strtotime((string) $value) ?: throw new RuntimeException("Filtr date: nie rozpoznano daty '{$value}'"))),
                'upper'   => mb_strtoupper((string) $value),
                'lower'   => mb_strtolower((string) $value),
                'default' => $value === null || $value === '' ? ($arg ?? '') : $value,
                default   => throw new RuntimeException("Nieznany filtr '{$name}' (dostępne: number, date, upper, lower, default)"),
            };
        }
        return $value ?? '';
    }

    public function lookup(string $path): mixed
    {
        $keys  = explode('.', $path);
        $value = $this->data->get(array_shift($keys));
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private function plugin(array $node, string $path): ?Component
    {
        $id     = $node['source'] ?? throw new RuntimeException("{$path}: Plugin bez pola 'source'");
        $source = $this->sources[$id] ?? null;
        if (!$source instanceof PluginSource) {
            throw new RuntimeException("{$path}: '{$id}' nie jest pluginem");
        }
        $data = $this->data->get($id);
        if ($data === null || AbstractPlugin::skipReason($data) !== null) {
            return null;
        }

        $content = $source->plugin()->component($data);
        $this->used[$id] = true;

        // Błąd rysowania jednego pluginu nie może położyć całego slajdu
        return new Custom(function (GdImage $img, Bounds $bounds) use ($content, $source): int {
            try {
                return $content->render($img, $bounds);
            } catch (\Throwable $e) {
                Console::error("{$source->label()}: {$e->getMessage()}");
                return 0;
            }
        });
    }

    /**
     * Plugin JSON: jego bloki budujemy tymi samymi danymi, ale bez śledzenia — wnętrze należy do innego pliku,
     * więc w podglądzie slajdu cały plugin jest jednym blokiem (klik zaznacza węzeł Plugin).
     */
    private function jsonPlugin(array $node, string $path): ?Component
    {
        $id         = (string) $node['plugin'];
        $definition = $this->plugins[$id] ?? throw new RuntimeException("{$path}: brak pluginu plugins/{$id}.json");
        if (isset($definition['error'])) {
            throw new RuntimeException("{$path} (Plugin {$id}): {$definition['error']}");
        }
        if (!JsonPlugins::enabled($definition) || $this->skippedSources($definition)) {
            return null;
        }
        if ($this->depth >= JsonPlugins::MAX_DEPTH) {
            throw new RuntimeException("{$path} (Plugin {$id}): za głębokie zagnieżdżenie pluginów — plugin wstawia sam siebie?");
        }

        $inner = new self($this->data, $this->sources, null, null, $this->plugins, $this->depth + 1, $this->used, $this->drawn, date: $this->date);
        try {
            $built = $inner->build($definition['body']);
            if ($built !== null) {
                $this->drawn[$id] = true;
            }
            // Pluginy wstawione wprost w slajd leżą na szklanej karcie (Theme)
            return $built !== null && $this->depth === 0 ? new Card($built) : $built;
        } catch (RuntimeException $e) {
            throw new RuntimeException("{$path} (Plugin {$id}): plugins/{$id}.json → {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Dzieci w kolejności do narysowania. Klucze zostają oryginalnymi indeksami z pliku —
     * ścieżki (body.children[2]) muszą wskazywać ten sam węzeł co w edytorze, także po wymieszaniu.
     */
    private function ordered(array $children, ?string $order, ?int $pick, string $path, ?int $budget = null): array
    {
        if ($order !== 'daily' && $pick === null) {
            return $children;
        }

        // Seed z daty = ten sam wybór i kolejność przez cały dzień. Losowanie ważone (Efraimidis–Spirakis:
        // klucz u^(1/waga), największe pierwsze): "weight" węzła (domyślnie 1), a blok wylosowany wczoraj
        // albo przedwczoraj ma wagę zmniejszoną (PickHistory::PENALTY) — rzadziej powtarza się dzień po dniu
        mt_srand((int) str_replace('-', '', $this->date));
        $recent = $this->recent[$path] ?? [];
        $scores = [];
        foreach ($children as $k => $child) {
            $weight     = max(0.01, (float) ($child['weight'] ?? 1)) * (PickHistory::PENALTY[$recent[$this->pickId($child, $k)] ?? 0] ?? 1);
            $scores[$k] = (mt_rand(1, mt_getrandmax()) / mt_getrandmax()) ** (1 / $weight);
        }
        mt_srand(); // reset seeda, żeby nie wpłynąć na inne losowania w tym requeście
        arsort($scores);
        $keys = array_keys($scores);

        if ($pick !== null) {
            // Bierzemy pierwsze N z wymieszanej listy, ale tylko bloki, które dziś mają co pokazać —
            // pominięty (when, skip pluginu) oddaje miejsce kolejnemu, a wybór reszty zostaje stały przez cały dzień.
            // Sprawdzamy po kolei i kończymy na N-tym, więc dane niewylosowanych bloków nie są pobierane.
            $forced = $this->forcedChild($path, array_keys($children));
            $keys   = $budget === null ? $this->pickCounted($children, $keys, $pick, $forced) : $this->pickFilled($children, $keys, $budget, $forced);
            $this->picked[$path] = array_map(fn($k) => $this->pickId($children[$k], $k), $keys);
        }

        $ordered = [];
        foreach ($keys as $k) {
            $ordered[$k] = $children[$k];
        }
        return $ordered;
    }

    /**
     * Rozmiar pluginu JSON w ćwiartkach (1–4) na dziś: "size" to liczba albo placeholder z danych,
     * np. "{{curiosity.size}}" (zdjęcie dnia bywa poziome albo pionowe). Brak pola, brak danych, skip()
     * albo wartość spoza 1–4 → DEFAULT_SIZE. Placeholder pobiera dane źródła (leniwie, jak każdy blok).
     */
    public function pluginSize(array $definition): int
    {
        return $this->clampSize($this->interpolateSize($definition['size'] ?? null));
    }

    /** Placeholder rozmiaru czytamy przez lookup(), nie interpolate() — samo sprawdzenie rozmiaru kandydata
     *  w 'pick' nie może dopisać jego źródła do stopki ({{credits}}) */
    private function interpolateSize(mixed $size): mixed
    {
        if (is_string($size) && preg_match('/^' . self::PLACEHOLDER . '$/', $size, $m)) {
            return $this->lookup($m[1]);
        }
        return $size;
    }

    private function clampSize(mixed $size): int
    {
        $size = is_numeric($size) ? (int) $size : 0;
        return $size >= 1 && $size <= VerticalSplit::QUARTERS ? $size : self::DEFAULT_SIZE;
    }

    /** Pula bez budżetu: N pierwszych dostępnych; wymuszony węzeł zajmuje miejsce ostatniego — liczba dzieci jak w CRON-ie */
    private function pickCounted(array $children, array $keys, int $pick, ?int $forced): array
    {
        $chosen = [];
        foreach ($keys as $k) {
            if (count($chosen) >= $pick) {
                break;
            }
            if ($this->available($children[$k])) {
                $chosen[] = $k;
            }
        }
        if ($forced !== null && !in_array($forced, $chosen, true)) {
            if ($chosen) {
                $chosen[count($chosen) - 1] = $forced;
            } else {
                $chosen[] = $forced;
            }
        }
        return $chosen;
    }

    /**
     * Pula na slajdzie (budżet ćwiartek): slajd ma być pełny, więc losujemy, dopóki zostały wolne ćwiartki —
     * 'pick' nie ogranicza liczby. Blok, który się nie mieści, jest pomijany. Blok, po którym reszty nie da się
     * dopełnić dalszymi kandydatami (np. 1/4 + 2/4 w puli 4/4 bez drugiego 1/4), też — o ile bez niego się da;
     * gdy dokładnie nie da się wcale, bierzemy jak leci. Wymuszony węzeł wchodzi zawsze, reszta dopełnia budżet.
     */
    private function pickFilled(array $children, array $keys, int $budget, ?int $forced): array
    {
        $size   = fn(int $k): int => $this->staticSize($children[$k]) ?? 1;
        $chosen = $forced !== null ? [$forced] : [];
        $left   = $budget - ($forced !== null ? $size($forced) : 0);
        $rest   = array_values(array_filter($keys, fn($k) => $k !== $forced));

        foreach ($rest as $i => $k) {
            if ($left <= 0) {
                break;
            }
            if ($size($k) > $left) {
                continue;
            }
            $later = array_map($size, array_slice($rest, $i + 1));
            if (!$this->fillable($left - $size($k), $later) && $this->fillable($left, $later)) {
                continue;
            }
            if ($this->available($children[$k])) {
                $chosen[] = $k;
                $left    -= $size($k);
            }
        }
        // Kolejność jak w dziennym losowaniu (wymuszony na swoim miejscu)
        return array_values(array_filter($keys, fn($k) => in_array($k, $chosen, true)));
    }

    /** Blok w historii puli: id pluginu (przetrwa zmianę kolejności w puli) albo numer dziecka */
    private function pickId(array $child, int $k): string
    {
        return ($child['type'] ?? null) === self::PLUGIN && isset($child['plugin']) ? (string) $child['plugin'] : (string) $k;
    }

    /** Czy z części rozmiarów da się złożyć dokładnie $target ćwiartek (0 — zawsze). */
    private function fillable(int $target, array $sizes): bool
    {
        $reach = [0 => true];
        foreach ($sizes as $s) {
            foreach (array_keys($reach) as $r) {
                if ($r + $s <= $target) {
                    $reach[$r + $s] = true;
                }
            }
        }
        return isset($reach[$target]);
    }

    private function pickCount(mixed $pick): ?int
    {
        return $pick === null || $pick === '' ? null : (int) $pick;
    }

    /**
     * Rozmiar węzła w ćwiartkach znany przed zbudowaniem: plugin — "quarters"/"size" węzła albo "size"
     * z plugins/<id>.json (domyślnie 2), kontener bez 'pick' — z dzieci (suma, Columns — największe).
     * Kontener z 'pick' i zwykłe bloki bez "quarters" — null (dostają miejsce, które zostało).
     */
    private function staticSize(array $node): ?int
    {
        if (isset($node['quarters'])) {
            return (int) $node['quarters'];
        }
        $type = $node['type'] ?? null;
        if ($type === self::PLUGIN) {
            if (isset($node['size'])) {
                return $this->clampSize($this->interpolateSize($node['size']));
            }
            return isset($node['plugin']) && isset($this->plugins[$node['plugin']])
                ? $this->pluginSize($this->plugins[$node['plugin']])
                : self::DEFAULT_SIZE;
        }
        if (!in_array($type, self::CONTAINERS, true) || $this->pickCount($node['pick'] ?? null) !== null) {
            return null;
        }
        $sizes = array_filter(array_map(fn($child) => $this->staticSize($child), $node['children'] ?? []), fn($s) => $s !== null);
        return $sizes ? ($type === 'Columns' ? max($sizes) : array_sum($sizes)) : null;
    }

    /**
     * Ile ćwiartek dostaje każde dziecko kontenera bez 'pick': obok siebie (Columns) — tyle co kontener;
     * pod sobą — dzieci o znanym rozmiarze zajmują swoje, a reszta (np. pula z 'pick') dzieli to, co zostało.
     * @return array<int, int>
     */
    private function capacities(string $type, array $children, ?int $capacity): array
    {
        if ($capacity === null) {
            return [];
        }
        if ($type === 'Columns') {
            return array_map(fn() => $capacity, $children);
        }
        $fixed   = 0;
        $dynamic = [];
        foreach ($children as $i => $child) {
            $size = $this->staticSize($child);
            if ($size === null) {
                $dynamic[] = $i;
            } elseif ($this->available($child)) {
                $fixed += $size;
            }
        }
        $out = [];
        foreach ($children as $i => $child) {
            $out[$i] = in_array($i, $dynamic, true) ? intdiv(max(0, $capacity - $fixed), count($dynamic)) : ($this->staticSize($child) ?? 0);
        }
        return $out;
    }

    /** Czy blok narysuje się dziś: spełnione 'when' i — dla pluginu — jest włączony i ma dane, które nie są skip(). */
    private function available(array $node): bool
    {
        if (isset($node['when']) && !$this->truthy($this->lookup($node['when']))) {
            return false;
        }
        if (($node['type'] ?? null) === self::PLUGIN && isset($node['plugin'])) {
            // Plugin JSON jest dostępny, gdy się wczytał, jest włączony i jego główny blok przechodzi 'when'
            $definition = $this->plugins[$node['plugin']] ?? null;
            return $definition !== null && !isset($definition['error']) && JsonPlugins::enabled($definition)
                && !$this->skippedSources($definition)
                && $this->available($definition['body']);
        }
        if (($node['type'] ?? null) === self::PLUGIN) {
            $data = $this->data->get((string) ($node['source'] ?? ''));
            return $data !== null && AbstractPlugin::skipReason($data) === null;
        }
        return true;
    }

    /**
     * Czy plugin JSON nie ma dziś czego pokazać: któreś źródło ma skip() albo główne (pierwsze) nic nie dało —
     * błąd API bez świeżych danych zapasowych. Brak danych pomocniczego źródła (np. 'aurora' w pogodzie) nie wyklucza.
     */
    private function skippedSources(array $definition): bool
    {
        foreach (array_values($definition['sources'] ?? []) as $i => $id) {
            $data = $this->data->get($id);
            if (($i === 0 && $data === null) || AbstractPlugin::skipReason($data) !== null) {
                return true;
            }
        }
        return false;
    }

    /** Indeks dziecka kontenera $path, w którym leży wymuszony węzeł — albo null. */
    private function forcedChild(string $path, array $keys): ?int
    {
        foreach ($keys as $k) {
            $child = "{$path}.children[{$k}]";
            if ($this->force === $child || str_starts_with((string) $this->force, $child . '.')) {
                return $k;
            }
        }
        return null;
    }

    private function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === []);
    }

    private function font(string $name, string $path): string
    {
        return match ($name) {
            'regular' => FONT,
            'bold'    => FONT_BOLD,
            default   => throw new RuntimeException("{$path}: nieznany font '{$name}' (dozwolone: regular, bold)"),
        };
    }
}
