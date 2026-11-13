<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/** Prognoza na 12:00 (Open-Meteo) dla 9 miast — mapa cieplna Polski, mediana temperatury i komentarz. */
class WeatherPlugin extends AbstractPlugin
{
    private const CITIES = [
        'Warszawa' => ['lat' => 52.23, 'lon' => 21.01],
        'Kraków'   => ['lat' => 50.06, 'lon' => 19.94],
        'Gdańsk'   => ['lat' => 54.35, 'lon' => 18.65],
        'Wrocław'  => ['lat' => 51.11, 'lon' => 17.04],
        'Poznań'   => ['lat' => 52.41, 'lon' => 16.93],
        'Łódź'     => ['lat' => 51.77, 'lon' => 19.46],
        'Szczecin' => ['lat' => 53.43, 'lon' => 14.55],
        'Lublin'   => ['lat' => 51.25, 'lon' => 22.57],
        'Nysa'     => ['lat' => 50.48, 'lon' => 17.20],
    ];

    private const VIEW = [14.1, 24.2, 49.0, 54.9];

    private const PALETTE = [
        [0,  [200, 230, 255], 90],
        [10, [80, 120, 255], 100],
        [15, [100, 180, 255], 110],
        [20, [100, 200, 100], 110],
        [25, [255, 200, 0], 100],
        [30, [255, 140, 0], 90],
        [35, [255, 80, 80], 90],
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("WeatherPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Pogoda w Polsce'; }

    public function getCredit(): string { return 'Open-Meteo'; }

    public function fetchData(): array
    {
        Console::info("Pobieram dane pogodowe dla miast...");
        $cities = [];
        foreach (self::CITIES as $name => $coords) {
            try {
                $data = $this->http->get('https://api.open-meteo.com/v1/forecast?' . http_build_query([
                    'latitude'  => $coords['lat'],
                    'longitude' => $coords['lon'],
                    'hourly'    => 'temperature_2m,relative_humidity_2m,weathercode,windspeed_10m',
                    'timezone'  => 'Europe/Warsaw',
                ]));
                $index = array_search(date('Y-m-d') . 'T12:00', $data['hourly']['time'] ?? [], true);
                if ($index === false) {
                    throw new RuntimeException('Brak prognozy dla godziny 12:00');
                }
                $cities[$name] = $coords + [
                    'temp'      => $data['hourly']['temperature_2m'][$index] ?? null,
                    'humidity'  => $data['hourly']['relative_humidity_2m'][$index] ?? null,
                    'windspeed' => $data['hourly']['windspeed_10m'][$index] ?? null,
                    'code'      => $data['hourly']['weathercode'][$index] ?? null,
                ];
            } catch (\Throwable $e) {
                Console::warn("Brak danych dla $name: " . $e->getMessage());
            }
        }

        if ($cities === []) {
            throw new RuntimeException('Brak danych pogodowych dla jakiegokolwiek miasta');
        }

        $medianTemp     = $this->median(array_filter(array_column($cities, 'temp'), fn($t) => $t !== null));
        $medianHumidity = $this->median(array_filter(array_column($cities, 'humidity'), fn($h) => $h !== null));

        return [
            'cities'         => $cities,
            'medianTemp'     => $medianTemp,
            'medianHumidity' => $medianHumidity,
        ];
    }

    /**
     * Pola dla plugins/weather.json: mediana, wilgotność (i odczuwalna, gdy wyraźnie wyższa), punkty miast i paleta
     * mapy. Tekst „nastroju” przed wilgotnością to `variants` w plugins/weather.json — zakresy edytowalne w edytorze.
     */
    public function present(array $data): array
    {
        $median  = $data['medianTemp'];
        $details = [];
        if ($data['medianHumidity'] !== null) {
            $details[] = sprintf('wilg. %.0f%%', $data['medianHumidity']);
            $feels     = $this->heatIndex($median, $data['medianHumidity']);
            if ($feels - $median >= 1) {
                $details[] = sprintf('odczuwalnie %.0f°C', $feels);
            }
        }

        $points = [];
        foreach ($data['cities'] as $name => $c) {
            if ($c['temp'] === null) {
                continue;
            }
            $points[] = [
                'lon'      => $c['lon'],
                'lat'      => $c['lat'],
                'value'    => (float) $c['temp'],
                'label'    => sprintf('%s %d°C', $name, (int) round($c['temp'])), // (int) — bez „-0°C” przy -0,3
                'sub'      => $c['humidity'] !== null ? sprintf('%.0f%%', $c['humidity']) : null,
                'color'    => $this->tempLabelColor($this->heatIndex((float) $c['temp'], $c['humidity'])),
                'priority' => true,
            ];
        }

        return [
            'medianText' => sprintf('Mediana %s°C', number_format($median, 1, ',', '')),
            'details'    => $details ? ' · ' . implode(' · ', $details) : '',
            'subColor'   => $this->tempLabelColor($median),
            'points'     => $points,
            'palette'    => self::PALETTE,
        ] + $data;
    }

    private function tempLabelColor(float $temp): string
    {
        return match (true) {
            $temp >= 35 => '#ff4444',
            $temp >= 30 => '#ff8800',
            $temp >= 25 => '#ffcc00',
            $temp >= 20 => '#88dd00',
            $temp >= 15 => '#44bbff',
            $temp >= 10 => '#4488ff',
            default   => '#aaddff',
        };
    }

    private function median(array $values): ?float
    {
        $values = array_values($values);
        $count  = count($values);
        if ($count === 0) {
            return null;
        }
        sort($values);
        $middle = intdiv($count, 2);

        return round($count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle], 1);
    }

    /** Temperatura odczuwalna — przy wilgotności > 40% i temperaturze ≥ 20°C */
    private function heatIndex(float $temp, ?float $humidity): float
    {
        if ($humidity === null || $temp < 20) {
            return $temp;
        }
        return $temp + max(0, ($humidity - 40) / 100) * ($temp - 20) * 0.6;
    }
}
