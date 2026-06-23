<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Helper\Console;

use DateTimeImmutable;
use RuntimeException;
use GdImage;

class NewsModule implements StoryModule
{
    public function __construct(
        private readonly JsonClient $http,
    ){
        Console::module("Moduł NewsModule zainicjalizowany");
    }

    public function getLabel(): string { return 'Wiadomości'; }
    
    public function run(): StoryPayload {
        try { $btcData = $this->getBTCData(); } catch (RuntimeException $e) {
            Console::error("Nie udało się pobrać danych BTC: " . $e->getMessage());
            $btcData = ['price' => 'N/A', 'change' => 'N/A'];
        }
        Console::info("Pobrano dane BTC");
        Console::debug("🪙 BTC: $" . $btcData['price'] . ' (' . $btcData['change'] . '%)');

        try { $peopleData = $this->getPeopleInSpaceData(); } catch (RuntimeException $e) {
            Console::error("Nie udało się pobrać danych o ludziach w kosmosie: " . $e->getMessage());
            $peopleData = ['number' => 'N/A', 'people' => []];
        }
        Console::info("Pobrano dane o ludziach w kosmosie");
        Console::debug("🚀 Ludzie w kosmosie: " . $peopleData['number']);
        
        try { $earthquakeData = $this->getEarthquakeData(); } catch (RuntimeException $e) {
            Console::error("Nie udało się pobrać danych o trzęsieniach ziemi: " . $e->getMessage());
            $earthquakeData = ['mag' => 'N/A', 'place' => 'N/A'];
        }
        Console::info("Pobrano dane o trzęsieniach ziemi");
        Console::debug("💥 Trzęsienie ziemi: " . $earthquakeData['mag'] . ' w ' . $earthquakeData['place']) ;

        try { $populationData = $this->getWorldPopulationData(); } catch (RuntimeException $e) {
            Console::error("Nie udało się pobrać danych o populacji świata: " . $e->getMessage());
            $populationData = ['count' => 'N/A', 'daily_added' => 'N/A'];
        }
        Console::info("Pobrano dane o populacji świata");
        Console::debug("🌍 Populacja świata: " . $populationData['count'] . ' (+ ' . $populationData['daily_added'] . ' dziennie)');

        return StoryPayload::single('debug path', 'debug url', 'Wiadomości');
    }

    private function getEarthquakeData(): ?array {
        $data = $this->http->get(
            'https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&minmagnitude=5&limit=1&orderby=time'
        );

        $feature = $data['features'][0] ?? null;
        if (!$feature) {
            return ['mag' => null, 'place' => 'brak danych'];
        }
        return [
            'mag'   => (float)$feature['properties']['mag'],
            'place' => $this->http->translate($feature['properties']['place']),
        ];
    }

    private function getBTCData(): ?array {
        $dataUrl = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd&include_24hr_change=true';

        $response = $this->http->getCurl($dataUrl);
        $data = $response['data'];

        $price = $data['bitcoin']['usd'] ?? null;
        $change = $data['bitcoin']['usd_24h_change'] ?? null;
        return [
            'price' => $price,
            'change' => $change,
        ];
    }

    private function getPeopleInSpaceData(): ?array {
        $data = $this->http->get('http://api.open-notify.org/astros.json');
        return [
            'number' => (int)($data['number'] ?? 0),
            'people' => $data['people'] ?? [],
        ];
    }

    // Źródło: ONZ WordPopulation Prospects 2024
    private function getWorldPopulationData(): ?array {
        // Baza: ~8 119 301 000 na 1 stycznia 2025
        // Przyrost: ~2.3 osoby na sekundę netto
        $base      = 8_119_301_000;
        $baseTime  = mktime(0, 0, 0, 1, 1, 2025);
        $elapsed   = time() - $baseTime;
        $current   = $base + (int)($elapsed * 2.3);

        return [
            'count'       => $current,
            'daily_added' => 200_000,
        ];
    }
}


?>