<?php
namespace Swagnar\Igapi\Data\Sources;

use Swagnar\Igapi\Data\DataSource;
use RuntimeException;

/**
 * Zastępuje źródło, którego pliku nie dało się wczytać — błąd wychodzi przy pobieraniu,
 * więc widać go w edytorze (Integracje) i w logu CRON-a, a reszta źródeł działa dalej.
 */
final class BrokenSource implements DataSource
{
    public function __construct(
        private readonly string $id,
        private readonly string $error,
    ) {}

    public function id(): string { return $this->id; }

    public function label(): string { return $this->id . ' (błąd konfiguracji)'; }

    public function credit(): ?string { return null; }

    public function describe(): array { return ['error' => $this->error]; }

    public function maxFallbackAge(): ?int { return null; }

    public function fetch(): array
    {
        throw new RuntimeException($this->error);
    }
}
