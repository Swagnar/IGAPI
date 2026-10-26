<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;
use Swagnar\Igapi\Contracts\NewsPlugin;
use Swagnar\Igapi\Contracts\Bounds;

use Swagnar\Igapi\Plugins\BitcoinPlugin;
use Swagnar\Igapi\Plugins\PopulationPlugin;
use Swagnar\Igapi\Plugins\AsteroidsPlugin;
use Swagnar\Igapi\Plugins\DisasterPlugin;

use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Custom;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Spacer;
use Swagnar\Igapi\Layout\VerticalSplit;

use Swagnar\Igapi\Helper\Console;

use RuntimeException;
use GdImage;


class NewsModule implements StoryModule
{
    private const PLUGINS_PER_SLIDE = 2;
    private const SLOT_GAP          = 40; // odstęp między pluginami, żeby treść sąsiednich slotów się nie stykała

    private array $availablePlugins;

    public function __construct(private readonly JsonClient $http)
    {
        $this->availablePlugins = [
            new BitcoinPlugin($http),
            // new PopulationPlugin($http),
            new AsteroidsPlugin($http),
            // new DisasterPlugin($http),
        ];
    }

    public function getLabel(): string { return 'Wiadomości'; }

    public function run(): StoryPayload
    {
        $selected = $this->pickPlugins(count: self::PLUGINS_PER_SLIDE);
        if (empty($selected)) {
            throw new RuntimeException('Brak zarejestrowanych pluginów w NewsModule');
        }

        // Każdy plugin dostaje równy slot — kolejność losowa (pickPlugins shuffluje)
        $slots = new VerticalSplit(gap: self::SLOT_GAP);
        foreach ($selected as $plugin) {
            $slots->add($this->pluginSlot($plugin));
        }

        $slide = new Slide('ŚWIAT', footer: Slide::source('Źródło: nie interesuj się'));
        [$path, $url] = $slide->render($slots)->save('news');

        return StoryPayload::single($path, $url, $this->getLabel());
    }

    /**
     * Pobiera dane pluginu od razu i opakowuje jego rysowanie w komponent.
     * Błąd pojedynczego pluginu nie przerywa całego modułu — slot zostaje pusty.
     */
    private function pluginSlot(NewsPlugin $plugin): Component
    {
        Console::plugin("Uruchamiam plugin: " . $plugin->getLabel());

        try {
            $data = $plugin->fetchData();
        } catch (\Throwable $e) {
            Console::error("Plugin {$plugin->getLabel()} failed: " . $e->getMessage());
            return new Spacer(0);
        }

        return new Custom(function (GdImage $img, Bounds $bounds) use ($plugin, $data): int {
            try {
                $plugin->draw($img, $data, $bounds);
            } catch (\Throwable $e) {
                Console::error("Plugin {$plugin->getLabel()} failed: " . $e->getMessage());
            }
            return $bounds->height;
        });
    }

    private function pickPlugins(int $count): array
    {
        // Seed z daty = te same pluginy przez cały dzień
        $seed = (int) date('Ymd');
        mt_srand($seed);

        $keys = array_keys($this->availablePlugins);
        shuffle($keys); // deterministyczny shuffle dzięki seedowi
        mt_srand(); // reset seeda, żeby nie wpłynąć na inne losowania w tym requeście

        return array_map(
            fn($k) => $this->availablePlugins[$k],
            array_slice($keys, 0, $count)
        );
    }
}
