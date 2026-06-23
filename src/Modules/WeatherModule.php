<?php

namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use RuntimeException;
use GdImage;

class WeatherModule implements StoryModule {
    private const WEATHER_MAP_DIR = ROOT_DIR . '/weather-maps';
    private const WEATHER_MAP_URL = '/weather-maps';

    private const CITIES = [
        'Warszawa'  => ['lat' => 52.23, 'lon' => 21.01],
        'Kraków'    => ['lat' => 50.06, 'lon' => 19.94],
        'Gdańsk'    => ['lat' => 54.35, 'lon' => 18.65],
        'Wrocław'   => ['lat' => 51.11, 'lon' => 17.04],
        'Poznań'    => ['lat' => 52.41, 'lon' => 16.93],
        'Łódź'      => ['lat' => 51.77, 'lon' => 19.46],
        'Szczecin'  => ['lat' => 53.43, 'lon' => 14.55],
        'Lublin'    => ['lat' => 51.25, 'lon' => 22.57],
        'Nysa'      => ['lat' => 50.28, 'lon' => 17.20],
    ];

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
                    "&hourly=temperature_2m,weathercode,windspeed_10m" .
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

        $temps   = array_filter(array_column($cities, 'temp'), fn($t) => $t !== null);
        $avgTemp = round(array_sum($temps) / count($temps), 1);

        Console::info("Średnia temperatura: {$avgTemp}°C");

        [$path, $url] = $this->generateWeatherMap($cities, $avgTemp);
        return StoryPayload::single($path, $url, 'Pogoda');
    }

    private function generateWeatherMap(array $cities, float $avgTemp): array
    {
        $borderGeo = require ROOT_DIR . '/geo/poland-border.php';

        if(!is_array($borderGeo)) {
            throw new RuntimeException('Koordynaty granic Polski nie są tablicą');
        }

        $img = imagecreatetruecolor(WIDTH, HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $black);

        $lonMin = 14.1; $lonMax = 24.2;
        $latMin = 49.0; $latMax = 54.9;

        $mapWidth  = 760;
        $mapHeight = 760;
        $offsetX   = 140;
        $offsetY   = 80;

        GdImageHelper::centerText($img, 'POLSKA', 150, 72, '#ffffff', FONT);

        Console::info("Generuję mapę pogody dla Polski...");
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

            Console::debug("Uruchamiam closure dla $name");
            [$x, $y] = $project((float)$city['lat'], (float)$city['lon']);

            $points[] = [
                'name' => $name,
                'lat' => (float)$city['lat'],
                'lon' => (float)$city['lon'],
                'x' => $x,
                'y' => $y,
                'temp' => (float)$city['temp'],
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

        $borderColor = imagecolorallocate($img, 230, 230, 230);
        imagesetthickness($img, 2);
        imagepolygon($img, $borderPx, count($borderPx) / 2, $borderColor);
        imagesetthickness($img, 1);

        Console::info("Rysuję punkty miast i temperatury...");
        foreach ($points as $p) {
            $dot = imagecolorallocate($img, 255, 255, 255);
            imagefilledellipse($img, $p['x'], $p['y'], 8, 8, $dot);

            $labelColor = $this->gdHex($img, $this->tempLabelColor($p['temp']));
            $text = sprintf('%s %.0f°C', $p['name'], $p['temp']);

            imagettftext($img, 22, 0, $p['x'] + 12, $p['y'] - 10, $labelColor, FONT, $text);
        }

        $avgColor = $this->gdHex($img, $this->tempLabelColor($avgTemp));
        $white = imagecolorallocate($img, 255, 255, 255);
        $gray = imagecolorallocate($img, 136, 136, 136);

        $mood = $this->randomWeatherMood($avgTemp);

        imagettftext($img, 48, 0, 80, 1780, $white, FONT, sprintf('Średnia: %.1f°C', $avgTemp));
        imagettftext($img, 36, 0, 80, 1840, $avgColor, FONT, $mood);
        imagettftext($img, 22, 0, 80, 1890, $gray, FONT, 'Źródło: Open-Meteo');

        if (!is_dir($this::WEATHER_MAP_DIR)) {
            mkdir($this::WEATHER_MAP_DIR, 0775, true);
        }

        Console::info("Zapisuję obraz...");
        $filename = 'weather-map-' . date('Ymd-His') . '.png';
        $path = rtrim($this::WEATHER_MAP_DIR, '/') . '/' . $filename;
        $url = rtrim($this::WEATHER_MAP_URL, '/') . '/' . $filename;

        imagepng($img, $path, 6);
        imagedestroy($img);
        imagedestroy($mask);

        return [$path, $url];
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
            default     => '#aaddff',
        };
    }

    private function randomWeatherMood(float $avg): string
    {
        $moods = match (true) {
            $avg >= 35 => ['Lampa jak chuj', 'Lampa jak skurwysyn'],
            $avg >= 30 => ['Mogło być gorzej', 'Pszeniczne wejdzie jak złoto'],
            $avg >= 25 => ['Ciepło i przyjemnie', 'Idealne na grilla', 'Polskie lato w pełnej krasie'],
            $avg >= 20 => ['Idealnie', 'Entropia osiągnięta', 'Zostań tak na zawsze'],
            $avg >= 15 => ['Ni to dobrze ni to źle', 'Tak se bym powiedział'],
            $avg >= 10 => ['Lekka pizgawica', 'Jeszcze jakoś ujdzie', 'Piździ jak w Kieleckim'],
            $avg >= 5  => ['Większa pizgawica'],
            default    => ['Oho, umieramy', 'Jedziemy na sanki?'],
        };

        return $moods[array_rand($moods)];
    }

    private function gdHex(GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');

        return imagecolorallocate(
            $img,
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
    }
}