<?php

namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Layout\Custom;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;

use RuntimeException;
use GdImage;

class WeatherModule implements StoryModule {
    private const CITIES = [
        'Warszawa'  => ['lat' => 52.23, 'lon' => 21.01],
        'Kraków'    => ['lat' => 50.06, 'lon' => 19.94],
        'Gdańsk'    => ['lat' => 54.35, 'lon' => 18.65],
        'Wrocław'   => ['lat' => 51.11, 'lon' => 17.04],
        'Poznań'    => ['lat' => 52.41, 'lon' => 16.93],
        'Łódź'      => ['lat' => 51.77, 'lon' => 19.46],
        'Szczecin'  => ['lat' => 53.43, 'lon' => 14.55],
        'Lublin'    => ['lat' => 51.25, 'lon' => 22.57],
        'Nysa'      => ['lat' => 50.48, 'lon' => 17.20],
    ];

    private const MAP_SIZE = 760; // px, mapa jest kwadratowa

    public function __construct(
        private readonly JsonClient $http,
    ){
        Console::module("Moduł WeatherModule zainicjalizowany");
    }

    public function getLabel(): string { return 'Pogoda'; } 

    public function run(): StoryPayload {
        Console::info("Pobieram dane pogodowe dla miast...");
        $cities = [];
        foreach (self::CITIES as $name => $coords) {
            try {
                $data = $this->http->get(
                    "https://api.open-meteo.com/v1/forecast?" .
                    "latitude={$coords['lat']}" .
                    "&longitude={$coords['lon']}" .
                    "&hourly=temperature_2m,relative_humidity_2m,weathercode,windspeed_10m" .
                    "&timezone=Europe/Warsaw"
                );
                $targetHour = date('Y-m-d') . 'T12:00';

                $index = array_search(
                    $targetHour,
                    $data['hourly']['time'] ?? [],
                    true
                );

                if ($index === false) {
                    throw new RuntimeException("Brak prognozy dla godziny 12:00");
                }

                $cities[$name] = [
                    'lat' => $coords['lat'],
                    'lon' => $coords['lon'],
                    'temp' => $data['hourly']['temperature_2m'][$index] ?? null,
                    'humidity' => $data['hourly']['relative_humidity_2m'][$index] ?? null,
                    'windspeed' => $data['hourly']['windspeed_10m'][$index] ?? null,
                    'code' => $data['hourly']['weathercode'][$index] ?? null,
                ];
                Console::info("$name: {$cities[$name]['temp']}°C");
            } catch (\Throwable $e) {
                Console::warn("Brak danych dla $name: " . $e->getMessage());
            }
        }

        if (empty($cities)) {
            throw new RuntimeException("Brak danych pogodowych dla jakiegokolwiek miasta");
        }

        $temps = array_filter(array_column($cities, 'temp'), fn($t) => $t !== null);
        $humidities = array_filter(array_column($cities, 'humidity'), fn($h) => $h !== null);

        $medianTemp = $this->median($temps);
        $medianHumidity = $this->median($humidities);

        Console::info("Mediana temperatury: {$medianTemp}°C, mediana wilgotności: {$medianHumidity}%");

        [$path, $url] = $this->generateWeatherMap($cities, $medianTemp, $medianHumidity);
        return StoryPayload::single($path, $url, 'Pogoda');
    }

    private function idwTemp(int $x, int $y, array $points, float $power = 2.0): float
    {
        $num = 0.0;
        $den = 0.0;

        foreach ($points as $p) {
            $dx = $x - $p['x'];
            $dy = $y - $p['y'];
            $dist = sqrt($dx * $dx + $dy * $dy);

            if ($dist < 1) {
                return $p['temp'];
            }

            $weight = 1 / pow($dist, $power);
            $num += $weight * $p['temp'];
            $den += $weight;
        }

        return $den > 0 ? $num / $den : 0.0;
    }

    private function tempGradientColor(float $temp): array
    {
        $palette = [
            [0,  [200, 230, 255], 90],
            [10, [80, 120, 255], 100],
            [15, [100, 180, 255], 110],
            [20, [100, 200, 100], 110],
            [25, [255, 200, 0], 100],
            [30, [255, 140, 0], 90],
            [35, [255, 80, 80], 90],
        ];

        if ($temp <= 0) {
            return [200, 230, 255, 90];
        }

        if ($temp >= 35) {
            return [255, 80, 80, 90];
        }

        for ($i = 0; $i < count($palette) - 1; $i++) {
            [$t1, $c1, $a1] = $palette[$i];
            [$t2, $c2, $a2] = $palette[$i + 1];

            if ($temp >= $t1 && $temp <= $t2) {
                $k = ($temp - $t1) / ($t2 - $t1);

                return [
                    (int)round($c1[0] + ($c2[0] - $c1[0]) * $k),
                    (int)round($c1[1] + ($c2[1] - $c1[1]) * $k),
                    (int)round($c1[2] + ($c2[2] - $c1[2]) * $k),
                    (int)round($a1 + ($a2 - $a1) * $k),
                ];
            }
        }

        return [255, 255, 255, 100];
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

    private function randomWeatherMood(float $temp): string
    {
        $moods = match (true) {
            $temp >= 30 => ['Lampa jak chuj', 'Lampa jak skurwysyn'],
            $temp >= 25 => ['Prawie lampa', 'Damy radę'],
            $temp >= 20 => ['Idealnie', 'Entropia osiągnięta', 'Zostań tak na zawsze'],
            $temp >= 15 => ['Ni to dobrze ni to źle', 'Tak se bym powiedział'],
            $temp >= 10 => ['Lekka pizgawica', 'Jeszcze jakoś ujdzie', 'Piździ jak w Kieleckim'],
            $temp >= 5  => ['Większa pizgawica'],
            default    => ['Oho, umieramy', 'Sanki?'],
        };

        return $moods[array_rand($moods)];
    }

    private function median(array $values): ?float
    {
        $values = array_values($values); // reindeksacja, na wypadek dziur po array_filter
        $count  = count($values);

        if ($count === 0) return null;

        sort($values);

        $middle = (int)floor($count / 2);

        if ($count % 2 === 0) {
            // parzysta liczba elementów — średnia z dwóch środkowych
            return round(($values[$middle - 1] + $values[$middle]) / 2, 1);
        }

        // nieparzysta liczba elementów — środkowy element
        return round($values[$middle], 1);
    }

    private function heatIndex(float $temp, ?float $humidity): float
    {
        if ($humidity === null || $temp < 20) {
            return $temp;
        }

        $humidityFactor = ($humidity - 40) / 100; // >0 gdy wilgotność powyżej 40%
        $bonus = max(0, $humidityFactor) * ($temp - 20) * 0.6;

        return $temp + $bonus;
    }

    private function generateWeatherMap(array $cities, float $medianTemp, ?float $medianHumidity): array
    {
        $borderGeo = require ROOT_DIR . '/geo/poland-border.php';

        if(!is_array($borderGeo)) {
            throw new RuntimeException('Koordynaty granic Polski nie są tablicą');
        }

        $medianLine = sprintf('Mediana: %.1f°C', $medianTemp);
        if ($medianHumidity !== null) {
            $medianLine .= sprintf(' / wilg. %.0f%%', $medianHumidity);
        }

        $footer = (new Stack())
            ->add(new Text($medianLine, size: 48, color: '#FFFFFF', font: FONT, indent: 40, height: 60))
            ->add(new Text($this->randomWeatherMood($medianTemp), size: 36, color: $this->tempLabelColor($medianTemp), font: FONT, indent: 40, height: 60))
            ->add(new Text('Źródło: Open-Meteo', size: 22, color: '#888888', font: FONT, indent: 40, height: 50));

        if ($medianHumidity !== null) {
            $medianHi = $this->heatIndex($medianTemp, $medianHumidity);
            $footer->add(new Text(sprintf('heat index: %.1f°C', $medianHi), size: 14, color: '#888888', font: FONT, indent: 40, height: 26));
        }

        Console::info("Generuję mapę pogody dla Polski...");
        $slide = new Slide('POLSKA', 'o 12:00', footer: $footer, footerHeight: 200);
        $slide->render(new Custom(fn(GdImage $img, Bounds $bounds) => $this->drawMap($img, $bounds, $cities, $borderGeo)));
        [$path, $url] = $slide->save('weather-map');

        Console::success("Zapisano obraz: " . $path);

        return [$path, $url];
    }

    /** Mapa cieplna Polski (interpolacja IDW) z punktami miast, wycentrowana w bounds. */
    private function drawMap(GdImage $img, Bounds $bounds, array $cities, array $borderGeo): int
    {
        $lonMin = 14.1; $lonMax = 24.2;
        $latMin = 49.0; $latMax = 54.9;

        $mapWidth  = self::MAP_SIZE;
        $mapHeight = self::MAP_SIZE;
        $offsetX   = $bounds->centerX() - intdiv($mapWidth, 2);
        $offsetY   = $bounds->y + intdiv($bounds->height - $mapHeight, 2);

        $project = function(float $lat, float $lon) use ($lonMin, $lonMax, $latMin, $latMax, $mapWidth, $mapHeight, $offsetX, $offsetY): array {
            $x = ($lon - $lonMin) / ($lonMax - $lonMin) * $mapWidth + $offsetX;
            $y = ($latMax - $lat) / ($latMax - $latMin) * $mapHeight + $offsetY;
            return [(int)round($x), (int)round($y)];
        };

        // Rysowanie granicy Polski
        $borderPx = [];
        foreach ($borderGeo as [$lat, $lon]) {
            [$x, $y] = $project($lat, $lon);
            $borderPx[] = $x;
            $borderPx[] = $y;
        }

        $mask = imagecreatetruecolor(WIDTH, HEIGHT);
        $maskBlack = imagecolorallocate($mask, 0, 0, 0);
        // Tworzenie koloru białego w masce
        $maskWhite = imagecolorallocate($mask, 255, 255, 255);
        // Wypełnienie całego obrazu czarnym kolorem w masce
        imagefill($mask, 0, 0, $maskBlack);
        // Wypełnienie obszaru Polski białym kolorem w masce
        imagefilledpolygon($mask, $borderPx, count($borderPx) / 2, $maskWhite);

        $points = [];
        foreach ($cities as $name => $city) {
            if (!isset($city['lat'], $city['lon'], $city['temp']) || $city['temp'] === null) {
                continue;
            }

            [$x, $y] = $project((float)$city['lat'], (float)$city['lon']);

            $points[] = [
                'name' => $name,
                'lat' => (float)$city['lat'],
                'lon' => (float)$city['lon'],
                'x' => $x,
                'y' => $y,
                'temp' => (float)$city['temp'],
                'humidity' => isset($city['humidity']) ? (float)$city['humidity'] : null,
            ];
        }

        // Interpolacja temperatury dla każdego piksela na mapie
        if ($points) {
            for ($y = $offsetY; $y <= $offsetY + $mapHeight; $y++) {
                for ($x = $offsetX; $x <= $offsetX + $mapWidth; $x++) {
                    // Pomija piksele poza granicami Polski (sprawdza kanał alfa maski - wartość < 200 oznacza czarny kolor = poza Polską)
                    if ((imagecolorat($mask, $x, $y) & 0xFF) < 200) {
                        continue;
                    }

                    $temp = $this->idwTemp($x, $y, $points);
                    [$r, $g, $b, $alpha] = $this->tempGradientColor($temp);

                    $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
                    imagesetpixel($img, $x, $y, $color);
                }
            }
        }
        imagedestroy($mask);

        $borderColor = imagecolorallocate($img, 230, 230, 230);
        imagesetthickness($img, 2);
        imagepolygon($img, $borderPx, count($borderPx) / 2, $borderColor);
        imagesetthickness($img, 1);

        Console::info("Rysuję punkty miast i temperatury...");
        foreach ($points as $p) {
            $dot = imagecolorallocate($img, 255, 255, 255);
            imagefilledellipse($img, $p['x'], $p['y'], 8, 8, $dot);

            $hi = $this->heatIndex($p['temp'], $p['humidity']);

            if ($p['humidity'] !== null) {
                $humidityText = sprintf('%.0f%%', $p['humidity']);
                GdImageHelper::drawText($img, $humidityText, $p['x'] + 12, $p['y'] - 34, 16, '#7FA8C9', FONT);
            }

            $text = sprintf('%s %.0f°C', $p['name'], $p['temp']);
            GdImageHelper::drawText($img, $text, $p['x'] + 12, $p['y'] - 10, 22, $this->tempLabelColor($hi), FONT);
        }

        return $bounds->height;
    }
}
