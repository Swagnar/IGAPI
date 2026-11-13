<?php
namespace Swagnar\Igapi\Data;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Data\Sources\PluginSource;
use Swagnar\Igapi\Helper\Console;

/**
 * Dane źródeł pobierane leniwie — dopiero gdy szablon po nie sięgnie — i najwyżej raz na uruchomienie.
 * Dzięki temu CRON o 6:00 pobiera najpierw tylko to, co trafi na story (wylosowane z puli 'pick'),
 * a strona WWW dociąga resztę później z tego samego magazynu, bez ponownego pytania API.
 *
 *   fresh (CRON)      — DataCache::fresh(): API, przy błędzie ostatnie zapisane dane
 *   useCache (edytor) — DataCache::cached(): dane z pliku
 */
final class SourceStore
{
    /** @var array<string, ?array> id → dane po present() albo null, gdy źródło nic nie dało */
    private array $data = [];

    /** @param array<string, DataSource> $sources */
    public function __construct(
        private readonly array $sources,
        private readonly DataCache $cache,
        private readonly bool $useCache = false,
    ) {}

    public function has(string $id): bool
    {
        return isset($this->sources[$id]);
    }

    public function source(string $id): ?DataSource
    {
        return $this->sources[$id] ?? null;
    }

    /** @return array<string, DataSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function cache(): DataCache
    {
        return $this->cache;
    }

    /** Dane źródła gotowe dla szablonu; pierwsze wywołanie pobiera je przez DataCache. Nieznane źródło → null. */
    public function get(string $id): ?array
    {
        if (!array_key_exists($id, $this->data)) {
            $source = $this->sources[$id] ?? null;
            if ($source === null) {
                return null;
            }

            $value = $this->useCache ? $this->cache->cached($source) : $this->cache->fresh($source);
            if ($value === null) {
                Console::error("Brak danych ze źródła '{$id}' — bloki, które go potrzebują, zostaną pominięte");
            }
            $this->data[$id] = $value === null ? null : self::present($source, $value);
        }
        return $this->data[$id];
    }

    public function loaded(string $id): bool
    {
        return array_key_exists($id, $this->data);
    }

    /** Dane pluginu PHP uzupełnione o pola dla szablonu (AbstractPlugin::present) — dzień pominięty zostaje bez zmian. */
    public static function present(DataSource $source, array $data): array
    {
        if (!$source instanceof PluginSource || AbstractPlugin::skipReason($data) !== null) {
            return $data;
        }
        return $source->plugin()->present($data);
    }
}
