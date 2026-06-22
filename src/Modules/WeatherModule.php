<?php

namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;

use RuntimeException;
use GdImage;

class WeatherModule implements StoryModule {
    private const FONT = ROOT_DIR . '/Inter-Regular.ttf';
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
        'Białystok' => ['lat' => 53.13, 'lon' => 23.16],
        'Rzeszów'   => ['lat' => 50.04, 'lon' => 22.00],
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
                    "https://api.open-meteo.com/v1/forecast?latitude={$coords['lat']}&longitude={$coords['lon']}&current=temperature_2m,weathercode,windspeed_10m&timezone=Europe/Warsaw"
                );
                $cities[$name] = [
                    'lat'       => $coords['lat'],
                    'lon'       => $coords['lon'],
                    'temp'      => $data['current']['temperature_2m'] ?? null,
                    'windspeed' => $data['current']['windspeed_10m']  ?? null,
                    'code'      => $data['current']['weathercode']    ?? null,
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

        Console::info("Generuję mapę pogody dla Polski...");
        $project = function(float $lat, float $lon) use ($lonMin, $lonMax, $latMin, $latMax, $mapWidth, $mapHeight, $offsetX, $offsetY): array {
            $x = ($lon - $lonMin) / ($lonMax - $lonMin) * $mapWidth + $offsetX;
            $y = ($latMax - $lat) / ($latMax - $latMin) * $mapHeight + $offsetY;
            return [(int)round($x), (int)round($y)];
        };

        $borderGeo = [
            [54.17,14.12],    [54.20,14.35],    [54.25,14.60],    [54.30,14.85],
            [54.35,15.10],    [54.38,15.35],    [54.42,15.60],    [54.45,15.85],
            [54.48,16.10],    [54.50,16.35],    [54.52,16.60],    [54.53,16.85],
            [54.55,17.10],    [54.57,17.35],    [54.60,17.60],    [54.63,17.85],
            [54.65,18.10],    [54.68,18.35],    [54.70,18.55],    [54.75,18.70],
            [54.80,18.85],    [54.83,19.05],    [54.80,19.25],    [54.78,19.50],
            [54.72,19.70],    [54.68,19.90],    [54.65,20.10],    [54.62,20.30],
            [54.58,20.55],    [54.55,20.80],    [54.52,21.05],    [54.48,21.25],
            [54.43,21.45],    [54.40,21.65],    [54.38,21.90],    [54.35,22.10],
            [54.30,22.30],    [54.25,22.50],    [54.22,22.70],    [54.18,22.85],
            [54.12,23.05],    [54.05,23.25],    [53.98,23.40],    [53.90,23.52],
            [53.80,23.60],    [53.70,23.65],    [53.60,23.70],    [53.50,23.75],
            [53.40,23.80],    [53.28,23.85],    [53.15,23.88],    [53.02,23.90],
            [52.90,23.92],    [52.75,23.95],    [52.62,23.95],    [52.48,23.92],
            [52.35,23.88],    [52.20,23.82],    [52.08,23.78],    [51.95,23.70],
            [51.82,23.65],    [51.68,23.60],    [51.55,23.55],    [51.42,23.48],
            [51.30,23.42],    [51.18,23.35],    [51.05,23.28],    [50.92,23.22],
            [50.80,23.18],    [50.68,23.10],    [50.55,22.98],    [50.42,22.88],
            [50.30,22.78],    [50.18,22.65],    [50.08,22.52],    [49.98,22.38],
            [49.88,22.22],    [49.78,22.05],    [49.68,21.88],    [49.58,21.72],
            [49.48,21.55],    [49.38,21.38],    [49.28,21.20],    [49.18,21.02],
            [49.10,20.85],    [49.05,20.65],    [49.02,20.42],    [49.00,20.18],
            [49.02,19.95],    [49.05,19.72],    [49.08,19.48],    [49.12,19.25],
            [49.18,19.02],    [49.22,18.80],    [49.28,18.58],    [49.35,18.38],
            [49.42,18.18],    [49.48,17.98],    [49.52,17.75],    [49.55,17.52],
            [49.55,17.28],    [49.52,17.05],    [49.48,16.82],    [49.45,16.58],
            [49.42,16.35],    [49.40,16.12],    [49.38,15.88],    [49.40,15.65],
            [49.45,15.42],    [49.50,15.18],    [49.55,14.95],    [49.60,14.72],
            [49.65,14.50],    [49.70,14.30],    [50.85,15.00],    [51.02,15.02],
            [51.18,14.98],    [51.32,14.92],    [51.48,14.85],    [51.62,14.78],
            [51.75,14.70],    [51.88,14.62],    [52.00,14.58],    [52.12,14.55],
            [52.25,14.52],    [52.38,14.50],    [52.52,14.55],    [52.65,14.60],
            [52.75,14.62],    [52.88,14.55],    [53.00,14.48],    [53.12,14.42],
            [53.25,14.35],    [53.38,14.28],    [53.50,14.22],    [53.62,14.18],
            [53.75,14.15],    [53.88,14.12],    [54.00,14.10],    [54.10,14.10],
        ];

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

        if ($points) {
            for ($y = $offsetY; $y <= $offsetY + $mapHeight; $y++) {
                for ($x = $offsetX; $x <= $offsetX + $mapWidth; $x++) {
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

            imagettftext($img, 22, 0, $p['x'] + 12, $p['y'] - 10, $labelColor, $this::FONT, $text);
        }

        $avgColor = $this->gdHex($img, $this->tempLabelColor($avgTemp));
        $white = imagecolorallocate($img, 255, 255, 255);
        $gray = imagecolorallocate($img, 136, 136, 136);

        $mood = $this->randomWeatherMood($avgTemp);

        imagettftext($img, 48, 0, 80, 1780, $white, $this::FONT, sprintf('Średnia: %.1f°C', $avgTemp));
        imagettftext($img, 36, 0, 80, 1840, $avgColor, $this::FONT, $mood);
        imagettftext($img, 22, 0, 80, 1890, $gray, $this::FONT, 'Źródło: Open-Meteo');

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
            $avg >= 35 => ['Lampa jak chuj', 'Słońce chce twojej śmierci', 'Sahara dzwoniła'],
            $avg >= 30 => ['Upał nie do wytrzymania', 'Nawet cień się poci', 'Lody zjedzone zanim dotarły do ust'],
            $avg >= 25 => ['Ciepło i przyjemnie', 'Idealne na grilla', 'Polskie lato w pełnej krasie'],
            $avg >= 20 => ['Idealna temperatura', 'Okno otwarte życie piękne', 'Złota polska pogoda'],
            $avg >= 15 => ['Sweterek w sam raz', 'Kurtka czy nie kurtka — dylemat dnia'],
            $avg >= 10 => ['Kurtka obowiązkowa', 'Jesień daje znać o sobie'],
            $avg >= 5  => ['Zimno zostań w domu', 'Zima puka do drzwi'],
            default    => ['Syberyjski koszmar', 'Nawet mróz jest w szoku'],
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