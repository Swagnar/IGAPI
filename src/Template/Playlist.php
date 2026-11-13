<?php
namespace Swagnar\Igapi\Template;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Data\DataCache;
use Swagnar\Igapi\Data\DataSource;
use Swagnar\Igapi\Data\SourceStore;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Modules\PalestineModule;
use Swagnar\Igapi\Modules\RemembranceModule;
use RuntimeException;

/**
 * Plan publikacji — stories.json: kolejność slajdów i które są włączone.
 *
 *   [ { "id": "palestine", "enabled": true }, { "id": "kosmos", "enabled": true }, … ]
 *
 * id to moduł z kodu (CODE_MODULES) albo nazwa pliku slides/<id>.json. CRON publikuje tylko włączone,
 * w tej kolejności; nowy slajd z edytora trafia tu jako wyłączony.
 */
final class Playlist
{
    /** Slajdy rysowane kodem (np. tło z kropek w Palestynie) — nie da się ich opisać w JSON */
    public const CODE_MODULES = [
        'palestine'   => PalestineModule::class,
        'remembrance' => RemembranceModule::class,
    ];

    /** @param list<array{id: string, enabled: bool}> $entries */
    public function __construct(
        private readonly string $file,
        private array $entries,
    ) {}

    public static function fromFile(string $file): self
    {
        $entries = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new RuntimeException("Nieprawidłowy plan publikacji: {$file} (" . json_last_error_msg() . ')');
        }

        $entries = array_map(fn($e) => [
            'id'      => (string) ($e['id'] ?? throw new RuntimeException("{$file}: wpis bez pola 'id'")),
            'enabled' => (bool) ($e['enabled'] ?? true),
        ], $entries);

        return new self($file, $entries);
    }

    /** @return list<array{id: string, enabled: bool}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @param list<array{id: string, enabled: bool}> $entries */
    public function replace(array $entries): void
    {
        $ids = array_column($entries, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Plan publikacji: ten sam slajd występuje dwa razy');
        }
        $this->entries = array_map(fn($e) => ['id' => (string) $e['id'], 'enabled' => (bool) $e['enabled']], $entries);
    }

    public function add(string $id, bool $enabled): void
    {
        if (!in_array($id, array_column($this->entries, 'id'), true)) {
            $this->entries[] = ['id' => $id, 'enabled' => $enabled];
        }
    }

    public function save(): void
    {
        file_put_contents($this->file, SlideJson::encode($this->entries));
    }

    public static function isCode(string $id): bool
    {
        return isset(self::CODE_MODULES[$id]);
    }

    /**
     * Moduły do uruchomienia przez CRON — włączone, w kolejności z planu, kluczowane id slajdu.
     * Wpis bez modułu/pliku jest logowany i pomijany. $store — wspólne dane wszystkich slajdów (i strony WWW).
     *
     * @param array<string, DataSource> $sources
     * @return array<string, StoryModule>
     */
    public function modules(string $slidesDir, JsonClient $http, array $sources, DataCache $cache, ?SourceStore $store = null): array
    {
        $modules = [];
        foreach ($this->entries as $entry) {
            if (!$entry['enabled']) {
                continue;
            }

            $id = $entry['id'];
            if (self::isCode($id)) {
                $class     = self::CODE_MODULES[$id];
                $modules[$id] = new $class($http);
            } elseif (is_file("{$slidesDir}/{$id}.json")) {
                $modules[$id] = TemplateModule::fromFile("{$slidesDir}/{$id}.json", $sources, $cache, store: $store);
            } else {
                Console::error("Plan publikacji: brak modułu ani slides/{$id}.json — pomijam");
            }
        }
        return $modules;
    }
}
