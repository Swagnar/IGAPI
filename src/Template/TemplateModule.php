<?php
namespace Swagnar\Igapi\Template;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;
use Swagnar\Igapi\Data\DataCache;
use Swagnar\Igapi\Data\DataSource;
use Swagnar\Igapi\Data\SourceStore;
use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Theme;
use RuntimeException;

/**
 * Slajd opisany plikiem slides/<nazwa>.json zamiast kodem:
 *
 *   { "label": "Kosmos", "title": "KOSMOS", "footer": "Źródło: …",
 *     "sources": ["asteroids", "curiosity"], "body": { "type": "Stack", "children": [ … ] } }
 *
 * Źródła pobierane są przez SourceStore dopiero, gdy blok po nie sięgnie — w CRON-ie świeże, w podglądzie z cache.
 * CRON podaje wspólny magazyn wszystkim slajdom, żeby eksport na WWW użył tych samych danych bez ponownego pobierania.
 */
final class TemplateModule implements StoryModule
{
    private readonly SourceStore $store;

    /** @var list<string> pluginy JSON narysowane w ostatnim run() — dla strony WWW */
    private array $drawn = [];

    /** @var array<string, list<string>> co wylosowały pule w ostatnim slide() — dla PickHistory */
    private array $picked = [];

    /** @param array<string, DataSource> $sources */
    public function __construct(
        private readonly string $name,
        private readonly array $definition,
        private readonly array $sources,
        DataCache $cache,
        bool $useCache = false,
        ?SourceStore $store = null,
    ) {
        $this->store = $store ?? new SourceStore($sources, $cache, $useCache);
    }

    /**
     * Błąd w pliku nie rzuca od razu — wychodzi dopiero w run(), więc StoryRunner pominie tylko ten slajd.
     * @param array<string, DataSource> $sources
     */
    public static function fromFile(string $file, array $sources, DataCache $cache, bool $useCache = false, ?SourceStore $store = null): self
    {
        try {
            $definition = self::load($file);
        } catch (RuntimeException $e) {
            $definition = ['error' => $e->getMessage()];
        }
        return new self(basename($file, '.json'), $definition, $sources, $cache, $useCache, $store);
    }

    public static function load(string $file): array
    {
        $definition = json_decode((string) @file_get_contents($file), true);
        if (!is_array($definition)) {
            throw new RuntimeException("Nieprawidłowy szablon slajdu: {$file} (" . json_last_error_msg() . ')');
        }
        return $definition;
    }

    public function getLabel(): string
    {
        return $this->definition['label'] ?? $this->definition['title'] ?? $this->name;
    }

    public function run(): StoryPayload
    {
        [$path, $url] = $this->slide()->save($this->name);
        // Tylko prawdziwy run zapisuje, co wylosowały pule — jutro te bloki będą miały mniejszą szansę
        PickHistory::record($this->name, date('Y-m-d'), $this->picked);
        return StoryPayload::single($path, $url, $this->getLabel());
    }

    /**
     * Kalendarz pul: co 'pick' wylosuje w kolejnych $days dniach (od dziś). Przyszłe dni liczone na dzisiejszych
     * danych z cache (plugin z skip() może w tym dniu wypaść) i z historią, w której kolejne dni dopisują się
     * z symulacji — tak jak dopisywałby je poranny run.
     * @return array<string, array<string, list<string>>> data → pula → wylosowane bloki (id pluginu albo numer dziecka)
     */
    public function calendar(int $days = 7): array
    {
        if (!isset($this->definition['body'])) {
            throw new RuntimeException("{$this->name}: szablon bez pola 'body'");
        }
        $plugins = JsonPlugins::collect($this->definition['body']);
        $history = PickHistory::load()[$this->name] ?? [];
        $out     = [];
        for ($i = 0; $i < $days; $i++) {
            $date    = date('Y-m-d', strtotime("+{$i} day"));
            $factory = new ComponentFactory($this->store, $this->sources, plugins: $plugins, recent: PickHistory::recent($history, $date), date: $date);
            $factory->build($this->definition['body']);
            $out[$date] = $history[$date] = $factory->picked();
        }
        return $out;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function definition(): array
    {
        return $this->definition;
    }

    /** @return list<string> pluginy JSON, które narysowały się w ostatnim run() */
    public function drawn(): array
    {
        return $this->drawn;
    }

    /**
     * Podgląd w edytorze: PNG bez zapisu na dysk + prostokąty, w których narysowały się węzły.
     * $force — ścieżka węzła, który ma się narysować, choć dzisiejszy 'pick' go nie wylosował.
     * @return array{png: string, boxes: list<array{path: string, x: int, y: int, w: int, h: int}>}
     */
    public function preview(?string $force = null): array
    {
        $boxes = new \ArrayObject();
        $png   = $this->slide($boxes, $force)->png();

        return ['png' => $png, 'boxes' => $boxes->getArrayCopy()];
    }

    private function slide(?\ArrayObject $boxes = null, ?string $force = null): Slide
    {
        if (isset($this->definition['error'])) {
            throw new RuntimeException($this->definition['error']);
        }
        if (!isset($this->definition['body'])) {
            throw new RuntimeException("{$this->name}: szablon bez pola 'body'");
        }

        Theme::init($this->name);
        $plugins = JsonPlugins::collect($this->definition['body']);
        $this->validate(JsonPlugins::sources($this->definition, $plugins));
        $recent  = PickHistory::recent(PickHistory::load()[$this->name] ?? [], date('Y-m-d'));
        $factory = new ComponentFactory($this->store, $this->sources, $boxes, $force, $plugins, recent: $recent);
        $body    = $factory->build($this->definition['body']) ?? throw new RuntimeException("{$this->name}: pusta treść slajdu");
        $this->drawn  = $factory->drawn();
        $this->picked = $factory->picked();

        $title    = isset($this->definition['title']) ? $factory->interpolate($this->definition['title']) : null;
        $subtitle = isset($this->definition['subtitle']) ? $factory->interpolate($this->definition['subtitle']) : null;
        $footer   = $this->footer($factory);

        return (new Slide($title, $subtitle, $footer))->render($body);
    }

    /**
     * Stopka po narysowaniu treści: {{credits}} = dostawcy danych bloków, które dziś faktycznie są na slajdzie.
     * Gdy żaden blok nie ma dostawcy (np. same dane lokalne), stopka z {{credits}} znika zamiast „Źródło: ”.
     */
    private function footer(ComponentFactory $factory): ?Component
    {
        $text = $this->definition['footer'] ?? null;
        if ($text === null || $text === '') {
            return null;
        }
        if (preg_match(self::CREDITS, $text)) {
            $credits = $factory->credits();
            if ($credits === '') {
                return null;
            }
            $text = preg_replace(self::CREDITS, $credits, $text);
        }
        return Slide::source($factory->interpolate($text));
    }

    private const CREDITS = '/\{\{\s*credits\s*\}\}/';

    /** Dane pluginu PHP uzupełnione o pola dla szablonu (AbstractPlugin::present) — dzień pominięty zostaje bez zmian. */
    public static function present(DataSource $source, array $data): array
    {
        return SourceStore::present($source, $data);
    }

    /**
     * Źródła slajdu i użytych w nim pluginów JSON muszą istnieć — literówka w id wybucha od razu, a nie po cichu
     * jako pusty blok. Same dane pobiera SourceStore dopiero wtedy, gdy blok po nie sięgnie.
     * @param list<string> $ids
     */
    private function validate(array $ids): void
    {
        foreach ($ids as $id) {
            if (!$this->store->has($id)) {
                throw new RuntimeException("{$this->name}: nieznane źródło '{$id}'");
            }
        }
    }
}
