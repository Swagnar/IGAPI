<?php
namespace Swagnar\Igapi\Data\Sources;

use Swagnar\Igapi\Data\DataSource;
use RuntimeException;

/**
 * Jeden wpis dziennie z lokalnego pliku JSON (lista obiektów) — ten sam przez cały dzień,
 * bo losowanie jest zasiane datą.
 */
final class DailyFactSource implements DataSource
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $file,
    ) {}

    public function id(): string { return $this->id; }

    public function label(): string { return $this->label; }

    public function credit(): ?string { return null; }

    public function describe(): array
    {
        return ['file' => basename(dirname($this->file)) . '/' . basename($this->file)];
    }

    public function maxFallbackAge(): ?int { return null; }

    public function fetch(): array
    {
        $facts = json_decode((string) @file_get_contents($this->file), true);
        if (!is_array($facts) || $facts === []) {
            throw new RuntimeException("Brak wpisów w {$this->file}");
        }

        mt_srand((int) date('Ymd'));
        $index = mt_rand(0, count($facts) - 1);
        mt_srand(); // reset seeda, żeby nie wpłynąć na inne losowania w tym samym requeście

        return $facts[$index] + ['index' => $index, 'total' => count($facts)];
    }
}
