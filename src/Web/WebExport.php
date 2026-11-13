<?php
namespace Swagnar\Igapi\Web;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Data\SourceStore;
use Swagnar\Igapi\Data\Sources\PluginSource;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Theme;
use Swagnar\Igapi\Layout\VerticalSplit;
use Swagnar\Igapi\Template\ComponentFactory;
use Swagnar\Igapi\Template\JsonPlugins;
use Swagnar\Igapi\Template\TemplateModule;
use GdImage;

/**
 * Dane dla strony swagnar.dev: <dir>/data/today.json + obrazki slajdów i bloków pluginów (WebP, bez WebP — JPEG).
 *
 *   addStory()     — po publikacji: dzisiejsze slajdy i pluginy, które na nich są (dane już pobrane)
 *   addRemaining() — pozostałe pluginy; dopiero tu pobierają się ich dane
 *   write()        — zapis JSON-a; wołany po każdym kroku, więc strona ma dzisiejsze story, zanim dojdzie reszta
 *
 * Status pluginu: story (jest dziś na story), ready (ma dane, dziś poza story), empty (nie ma dziś czego pokazać —
 * skip() albo brak treści), failed (źródło nie dało danych). Komunikatów błędów API nie eksportujemy — bywają w nich URL-e.
 */
final class WebExport
{
    /** Odstęp między blokami na slajdzie — z nim i Slide::CONTENT_HEIGHT liczymy wysokość slotu pluginu */
    private const GAP = 40;
    private const SIDE = 40;
    private const QUALITY = 80;

    /** @var list<array> */
    private array $slides = [];

    /** @var array<string, array> */
    private array $plugins = [];

    /** @var array<string, string> plugin → id slajdu, w którego puli jest */
    private array $slideOf = [];

    private bool $published = false;

    /** @var ?array<string, ?string> cache dla extras() */
    private ?array $extras = null;

    public function __construct(
        private readonly string $dir,
        private readonly SourceStore $store,
    ) {}

    /**
     * @param array<string, StoryModule> $modules wynik Playlist::modules()
     * @param array{published: bool, payloads: array} $result wynik StoryRunner::run()
     */
    public function addStory(array $modules, array $result): void
    {
        $this->published = $result['published'];

        foreach ($modules as $id => $module) {
            $payload = $result['payloads'][$id] ?? null;
            $pool    = $module instanceof TemplateModule ? array_keys(JsonPlugins::collect($module->definition()['body'] ?? [])) : [];
            $drawn   = $module instanceof TemplateModule && $payload !== null ? $module->drawn() : [];
            foreach ($pool as $plugin) {
                $this->slideOf[$plugin] ??= $id;
            }

            $images = [];
            foreach ($payload?->slides ?? [] as $i => $slide) {
                $img = @imagecreatefrompng($slide['path']);
                if ($img !== false) {
                    $images[] = $this->save($img, 'slides/' . $id . ($i ? "-{$i}" : ''));
                }
            }

            $this->slides[] = [
                'id'      => $id,
                'label'   => $module->getLabel(),
                'ok'      => $payload !== null,
                'images'  => $images,
                'plugins' => $drawn,
                'pool'    => $pool,
            ];

            foreach ($drawn as $plugin) {
                $this->plugin($plugin, $id);
                // Źródła doklejone do narysowanego pluginu (zorza na mapie pogody) też są dziś na story
                foreach ($this->attached($plugin) as $extra) {
                    $this->extra($extra, $id);
                }
            }
        }
    }

    /** Pluginy spoza dzisiejszego story — ich dane pobierają się dopiero tutaj. */
    public function addRemaining(): void
    {
        foreach (JsonPlugins::ids() as $id) {
            if (!isset($this->plugins[$id])) {
                $this->plugin($id);
            }
        }
        foreach ($this->extras() as $id => $host) {
            if (!isset($this->plugins[$id])) {
                $this->extra($id);
            }
        }
    }

    /** Zapisuje data/today.json (atomowo — strona nigdy nie widzi połowy pliku). */
    public function write(bool $complete): string
    {
        $file = $this->dir . '/data/today.json';
        $json = json_encode([
            'generatedAt' => date(DATE_ATOM),
            'date'        => date('Y-m-d'),
            'complete'    => $complete,
            'published'   => $this->published,
            'slides'      => $this->slides,
            'plugins'     => $this->plugins,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->mkdir(dirname($file));
        file_put_contents($file . '.tmp', $json);
        rename($file . '.tmp', $file);
        return $file;
    }

    /** Plugin JSON: rysujemy go sam, w rozmiarze połowy slajdu — tak, jak wygląda na story. */
    private function plugin(string $id, ?string $story = null): void
    {
        try {
            $definition = JsonPlugins::load($id);
        } catch (\Throwable $e) {
            Console::error("WWW: {$e->getMessage()}");
            return;
        }
        if (!JsonPlugins::enabled($definition)) {
            return;
        }

        $sources = $definition['sources'] ?? [];
        $entry   = [
            'label'  => $definition['label'] ?? $id,
            'credit' => $this->credit($sources),
            'slide'  => $this->slideOf[$id] ?? null,
        ];

        $image = null;
        try {
            $image = $this->render($id);
        } catch (\Throwable $e) {
            Console::error("WWW: plugin {$id} — {$e->getMessage()}");
        }

        if ($image !== null) {
            $entry += ['status' => $story !== null ? 'story' : 'ready', 'note' => null, 'image' => $image];
        } else {
            $failed = array_filter($sources, fn($s) => $this->store->get($s) === null);
            $reason = null;
            foreach ($sources as $s) {
                $reason ??= AbstractPlugin::skipReason($this->store->get($s));
            }
            $entry += ['status' => $failed ? 'failed' : 'empty', 'note' => $reason, 'image' => null];
        }

        $this->plugins[$id] = $entry + $this->freshness($sources);
        Console::success("WWW: {$id} → {$this->plugins[$id]['status']}");
    }

    /** Źródło-plugin bez własnego bloku (np. zorza doklejana do pogody): sam status, bez obrazka. */
    private function extra(string $id, ?string $story = null): void
    {
        $source = $this->store->source($id);
        if (!$source instanceof PluginSource) {
            return;
        }

        $data   = $this->store->get($id);
        $reason = AbstractPlugin::skipReason($data);
        $status = match (true) {
            $data === null                              => 'failed',
            $reason !== null                            => 'empty',
            !$source->plugin()->hasContent($data)       => 'empty',
            $story !== null                             => 'story',
            default                                     => 'ready',
        };

        $host = $this->extras()[$id] ?? null;
        $this->plugins[$id] = [
            'label'  => $source->plugin()->getLabel(),
            'credit' => $source->credit(),
            'slide'  => $story ?? ($host !== null ? $this->slideOf[$host] ?? null : null),
            'status' => $status,
            'note'   => $reason,
            'image'  => null,
        ] + $this->freshness([$id]);
        Console::success("WWW: {$id} → {$status}");
    }

    /** Blok pluginu jako obrazek; null — plugin nie ma dziś czego narysować. */
    private function render(string $id): ?string
    {
        // Karta w kolorach slajdu, na którym plugin się losuje — Theme pamięta akcent ostatnio rysowanego slajdu
        Theme::init($this->slideOf[$id] ?? null);
        $node    = ['type' => ComponentFactory::PLUGIN, 'plugin' => $id];
        $factory = new ComponentFactory($this->store, $this->store->sources(), plugins: JsonPlugins::collect($node));
        $block   = $factory->build($node);
        if ($block === null) {
            return null;
        }

        $img  = GdImageHelper::createCanvas(WIDTH, HEIGHT);
        // Blok dostaje tyle miejsca, ile na story: "size" pluginu w ćwiartkach treści slajdu (1/4 … 4/4)
        $height = VerticalSplit::slot($factory->sizeOf($block) ?? ComponentFactory::DEFAULT_SIZE, Slide::CONTENT_HEIGHT, self::GAP);
        $used   = $block->render($img, new Bounds(self::SIDE, self::SIDE, WIDTH - 2 * self::SIDE, $height));
        if ($used <= 0) {
            imagedestroy($img);
            return null;
        }

        $crop = imagecrop($img, ['x' => 0, 'y' => 0, 'width' => WIDTH, 'height' => min(HEIGHT, $used + 2 * self::SIDE)]);
        imagedestroy($img);
        return $this->save($crop, "plugins/{$id}");
    }

    /** Zapis do <dir>/data/<name>.webp (albo .jpg); zwraca ścieżkę względną dla strony z wersją na cache przeglądarki. */
    private function save(GdImage $img, string $name): string
    {
        $webp = function_exists('imagewebp');
        $rel  = "data/{$name}." . ($webp ? 'webp' : 'jpg');
        $path = "{$this->dir}/{$rel}";
        $this->mkdir(dirname($path));

        $webp ? imagewebp($img, $path, self::QUALITY) : imagejpeg($img, $path, self::QUALITY);
        imagedestroy($img);
        return $rel . '?v=' . time();
    }

    /** @param list<string> $sources */
    private function credit(array $sources): ?string
    {
        $parts = array_filter(array_map(fn($s) => $this->store->source($s)?->credit(), $sources));
        return $parts ? implode(' / ', array_unique($parts)) : null;
    }

    /**
     * Kiedy dane były pobrane i czy to zapas po błędzie API (stale) — najstarsze ze źródeł pluginu.
     * @param list<string> $sources
     */
    private function freshness(array $sources): array
    {
        $fetched = null;
        $stale   = false;
        foreach ($sources as $s) {
            $entry = $this->store->cache()->entry($s);
            if (isset($entry['fetchedAt']) && ($fetched === null || $entry['fetchedAt'] < $fetched)) {
                $fetched = $entry['fetchedAt'];
            }
            $stale = $stale || ($entry !== null && ($entry['ok'] ?? true) === false && ($entry['data'] ?? null) !== null);
        }
        return ['fetchedAt' => $fetched, 'stale' => $stale];
    }

    /**
     * Pluginy PHP bez własnego plugins/<id>.json → plugin JSON, do którego są doklejone (pierwszy, który ma je w sources).
     * @return array<string, ?string>
     */
    private function extras(): array
    {
        if ($this->extras !== null) {
            return $this->extras;
        }

        $ids    = JsonPlugins::ids();
        $extras = [];
        foreach ($this->store->sources() as $id => $source) {
            if (!$source instanceof PluginSource || in_array($id, $ids, true)) {
                continue;
            }
            $extras[$id] = null;
            foreach ($ids as $plugin) {
                try {
                    if (in_array($id, JsonPlugins::load($plugin)['sources'] ?? [], true)) {
                        $extras[$id] = $plugin;
                        break;
                    }
                } catch (\Throwable) {
                }
            }
        }
        return $this->extras = $extras;
    }

    /** @return list<string> doklejone źródła pluginu JSON */
    private function attached(string $plugin): array
    {
        return array_keys(array_filter($this->extras(), fn($host) => $host === $plugin));
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
