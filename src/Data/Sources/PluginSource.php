<?php
namespace Swagnar\Igapi\Data\Sources;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Data\DataSource;

/**
 * Plugin jako źródło danych: fetchData() idzie przez DataCache, a w szablonie plugin rysuje się
 * węzłem { "type": "Plugin", "source": "<id>" } — własnym layoutem z content().
 */
final class PluginSource implements DataSource
{
    public function __construct(
        private readonly string $id,
        private readonly AbstractPlugin $plugin,
    ) {}

    public function id(): string { return $this->id; }

    public function label(): string { return 'Plugin: ' . $this->plugin->getLabel(); }

    public function credit(): ?string { return $this->plugin->getCredit(); }

    public function describe(): array
    {
        return ['plugin' => (new \ReflectionClass($this->plugin))->getShortName(), 'canSkip' => $this->plugin->canSkip()];
    }

    public function fetch(): array
    {
        return $this->plugin->fetchData();
    }

    public function maxFallbackAge(): ?int { return 20 * 3600; }

    public function plugin(): AbstractPlugin
    {
        return $this->plugin;
    }
}
