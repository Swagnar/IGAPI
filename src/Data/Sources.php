<?php
namespace Swagnar\Igapi\Data;

use Swagnar\Igapi\Data\Sources\AsteroidsSource;
use Swagnar\Igapi\Data\Sources\BrokenSource;
use Swagnar\Igapi\Data\Sources\CuriosityPhotoSource;
use Swagnar\Igapi\Data\Sources\DailyFactSource;
use Swagnar\Igapi\Data\Sources\HttpSource;
use Swagnar\Igapi\Data\Sources\PluginSource;
use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Plugins\AuroraPlugin;
use Swagnar\Igapi\Plugins\BigMacPlugin;
use Swagnar\Igapi\Plugins\BitcoinPlugin;
use Swagnar\Igapi\Plugins\ClimatePlugin;
use Swagnar\Igapi\Plugins\CurrenciesPlugin;
use Swagnar\Igapi\Plugins\EarthquakesPlugin;
use Swagnar\Igapi\Plugins\AvalanchePlugin;
use Swagnar\Igapi\Plugins\DoctorsPlugin;
use Swagnar\Igapi\Plugins\TrainsPlugin;
use Swagnar\Igapi\Plugins\ElNinoPlugin;
use Swagnar\Igapi\Plugins\HousingPlugin;
use Swagnar\Igapi\Plugins\ElectricityPlugin;
use Swagnar\Igapi\Plugins\FuelPlugin;
use Swagnar\Igapi\Plugins\InflationPlugin;
use Swagnar\Igapi\Plugins\IssPlugin;
use Swagnar\Igapi\Plugins\LaunchPlugin;
use Swagnar\Igapi\Plugins\PolishGamesPlugin;
use Swagnar\Igapi\Plugins\PopulationPlugin;
use Swagnar\Igapi\Plugins\SatellitesPlugin;
use Swagnar\Igapi\Plugins\VoyagerPlugin;
use Swagnar\Igapi\Plugins\FearGreedPlugin;
use Swagnar\Igapi\Plugins\SeaIcePlugin;
use Swagnar\Igapi\Plugins\SolarCyclePlugin;
use Swagnar\Igapi\Plugins\PlanesPlugin;
use Swagnar\Igapi\Plugins\MushroomsPlugin;
use Swagnar\Igapi\Plugins\PlanetsPlugin;
use Swagnar\Igapi\Plugins\WeatherPlugin;

/**
 * Rejestr źródeł dostępnych w szablonach slajdów: źródła w PHP dopisujemy tutaj,
 * proste źródła HTTP wystarczy dodać jako plik sources/<id>.json (HttpSource).
 */
final class Sources
{
    /** @return array<string, DataSource> */
    public static function all(JsonClient $http): array
    {
        $sources = [
            new AsteroidsSource($http),
            new CuriosityPhotoSource($http),
            new DailyFactSource('flatEarth', 'Fakty o płaskiej Ziemi', ROOT_DIR . '/data/flat_earth_facts.json'),
            new PluginSource('bitcoin', new BitcoinPlugin($http)),
            new PluginSource('population', new PopulationPlugin($http)),
            new PluginSource('earthquakes', new EarthquakesPlugin($http)),
            new PluginSource('electricity', new ElectricityPlugin($http)),
            new PluginSource('weather', new WeatherPlugin($http)),
            new PluginSource('polishGames', new PolishGamesPlugin($http)),
            new PluginSource('climate', new ClimatePlugin($http)),
            new PluginSource('launch', new LaunchPlugin($http)),
            new PluginSource('iss', new IssPlugin($http)),
            new PluginSource('aurora', new AuroraPlugin($http)),
            new PluginSource('bigMac', new BigMacPlugin($http)),
            new PluginSource('satellites', new SatellitesPlugin($http)),
            new PluginSource('voyager', new VoyagerPlugin($http)),
            new PluginSource('fearGreed', new FearGreedPlugin($http)),
            new PluginSource('seaIce', new SeaIcePlugin($http)),
            new PluginSource('planes', new PlanesPlugin($http)),
            new PluginSource('planets', new PlanetsPlugin()),
            new PluginSource('mushrooms', new MushroomsPlugin($http)),
            new PluginSource('solarCycle', new SolarCyclePlugin($http)),
            new PluginSource('currencies', new CurrenciesPlugin($http)),
            new PluginSource('inflation', new InflationPlugin($http)),
            new PluginSource('fuel', new FuelPlugin($http)),
            new PluginSource('elNino', new ElNinoPlugin($http)),
            new PluginSource('housing', new HousingPlugin($http)),
            new PluginSource('doctors', new DoctorsPlugin($http)),
            new PluginSource('avalanche', new AvalanchePlugin($http)),
            new PluginSource('trains', new TrainsPlugin($http)),
            // DisasterPlugin wyłączony — nie da się trafnie dobrać zdjęć
            ...self::fromFiles(ROOT_DIR . '/sources', $http),
        ];

        return array_combine(array_map(fn(DataSource $s) => $s->id(), $sources), $sources);
    }

    /** @return list<DataSource> */
    private static function fromFiles(string $dir, JsonClient $http): array
    {
        $sources = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (!preg_match('/^[A-Za-z_]\w*$/', $id)) {
                $sources[] = new BrokenSource(preg_replace('/\W/', '_', $id), "sources/{$id}.json: nazwa pliku musi być identyfikatorem (litery, cyfry, _), bo trafia do szablonu jako {{{$id}.…}}");
                continue;
            }
            try {
                $sources[] = HttpSource::fromFile($file, $http);
            } catch (\Throwable $e) {
                $sources[] = new BrokenSource($id, $e->getMessage());
            }
        }
        return $sources;
    }
}
