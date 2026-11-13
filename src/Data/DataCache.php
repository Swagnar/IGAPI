<?php
namespace Swagnar\Igapi\Data;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;

/**
 * Zapisuje wynik każdego źródła w cache/<id>.json razem z metadanymi pobrania (czas, status, błąd).
 *
 *   fresh()  — CRON: pobiera z API, przy błędzie oddaje ostatnie zapisane dane (o ile nie są za stare)
 *   cached() — podgląd w edytorze: dane z pliku, API tylko gdy pliku jeszcze nie ma
 */
final class DataCache
{
    /** @param ?JsonClient $http podany → zapytania HTTP źródła zapisują się w cache (podgląd „surowej” odpowiedzi w edytorze) */
    public function __construct(
        private readonly string $dir,
        private readonly ?JsonClient $http = null,
    ) {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    public function fresh(DataSource $source): ?array
    {
        $previous = $this->entry($source->id());
        $started  = microtime(true);
        $this->http?->startRecording();

        try {
            $data = $source->fetch();
        } catch (\Throwable $e) {
            Console::error("{$source->label()}: {$e->getMessage()}");
            $this->write($source, [
                'ok'          => false,
                'error'       => $e->getMessage(),
                'durationMs'  => $this->elapsedMs($started),
                'attemptedAt' => date(DATE_ATOM),
                'fetchedAt'   => $previous['fetchedAt'] ?? null,
                'data'        => $previous['data'] ?? null,
                'requests'    => $this->http?->stopRecording() ?? [],
            ]);
            return $this->usableFallback($source, $previous);
        }

        $this->write($source, [
            'ok'          => true,
            'error'       => null,
            'durationMs'  => $this->elapsedMs($started),
            'attemptedAt' => date(DATE_ATOM),
            'fetchedAt'   => date(DATE_ATOM),
            'data'        => $data,
            'requests'    => $this->http?->stopRecording() ?? [],
        ]);
        return $data;
    }

    public function cached(DataSource $source): ?array
    {
        return $this->entry($source->id())['data'] ?? $this->fresh($source);
    }

    /** Pełny wpis z metadanymi albo null, jeśli źródło nie było jeszcze pobierane. */
    public function entry(string $id): ?array
    {
        $file = $this->path($id);
        if (!is_file($file)) {
            return null;
        }
        $entry = json_decode((string) file_get_contents($file), true);
        return is_array($entry) ? $entry : null;
    }

    private function usableFallback(DataSource $source, ?array $previous): ?array
    {
        if (!isset($previous['data'], $previous['fetchedAt'])) {
            return null;
        }

        $age    = time() - strtotime($previous['fetchedAt']);
        $maxAge = $source->maxFallbackAge();
        if ($maxAge !== null && $age > $maxAge) {
            Console::warn("{$source->label()}: zapisane dane są za stare (" . round($age / 3600) . " h) — pomijam");
            return null;
        }

        Console::warn("{$source->label()}: używam danych z {$previous['fetchedAt']}");
        return $previous['data'];
    }

    private function write(DataSource $source, array $entry): void
    {
        $entry = [
            'source'         => $source->id(),
            'label'          => $source->label(),
            'describe'       => $source->describe(),
            'maxFallbackAge' => $source->maxFallbackAge(),
        ] + $entry;
        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        file_put_contents($this->path($source->id()), $json);
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
