<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;
use Swagnar\Igapi\Contracts\Bounds;


use Swagnar\Igapi\Plugins\BitcoinPlugin;
use Swagnar\Igapi\Plugins\PopulationPlugin;
use Swagnar\Igapi\Plugins\AsteroidsPlugin;
use Swagnar\Igapi\Plugins\DisasterPlugin;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use DateTimeImmutable;
use RuntimeException;
use GdImage;


class NewsModule implements StoryModule
{
    private array $availablePlugins;

    public function __construct(private readonly JsonClient $http)
    {
        $this->availablePlugins = [
            // new BitcoinPlugin($http),
            // new PopulationPlugin($http),
            new AsteroidsPlugin($http),
            new DisasterPlugin($http),
        ];
    }

    public function getLabel(): string { return 'Wiadomości'; }

    public function run(): StoryPayload
{
    $selected = $this->pickPlugins(count: 2);

    $img = GdImageHelper::createCanvas(WIDTH, HEIGHT);

    // Nagłówek: 140px, stopka: 60px
    $headerH = 180;
    $footerH = 80;
    $usable  = HEIGHT - $headerH - $footerH;  // przestrzeń dla pluginów

    GdImageHelper::centerText($img, 'ŚWIAT', 150, HEADER_SIZE, '#FFFFFF', FONT_BOLD);

    // Każdy plugin dostaje połowę — ale kolejność losowa (już jest, bo pickPlugins shuffluje)
    $slotH = intdiv($usable, count($selected));

    foreach ($selected as $i => $plugin) {
        Console::plugin("Uruchamiam plugin: " . $plugin->getLabel());

        $bounds = new Bounds(
            x:      40,
            y:      $headerH + $i * $slotH,
            width:  WIDTH - 80,
            height: $slotH,
        );

        try {
            $data = $plugin->fetchData();

            GdImageHelper::drawFrame($img, $bounds, 'Plugin: ' . $plugin->getLabel());

            $plugin->draw($img, $data, $bounds);
        } catch (\Throwable $e) {
            Console::error("Plugin {$plugin->getLabel()} failed: " . $e->getMessage());
        }
    }

    GdImageHelper::centerText($img, 'Źródło: nie interesuj się', HEIGHT - 40, 24, '#445577', FONT);

    [$path, $url] = GdImageHelper::saveImage($img, 'news', PUBLIC_DIR, PUBLIC_URL);
    return StoryPayload::single($path, $url, $this->getLabel());
} 

    private function pickPlugins(int $count): array
    {
        // Seed z daty = te same pluginy przez cały dzień
        $seed = (int) date('Ymd');
        mt_srand($seed);

        $keys = array_keys($this->availablePlugins);
        shuffle($keys); // deterministyczny shuffle dzięki seedowi

        return array_map(
            fn($k) => $this->availablePlugins[$k],
            array_slice($keys, 0, $count)
        );
    }
}