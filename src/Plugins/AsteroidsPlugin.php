<?php

namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\NewsPlugin;
use Swagnar\Igapi\Contracts\Bounds;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use GdImage;

class AsteroidsPlugin implements NewsPlugin {
    private const API_URL = 'https://api.nasa.gov/neo/rest/v1/feed';

    public function __construct(
        private readonly JsonClient $http
    ) {
        Console::boot("AsteroidsPlugin zainicjalizowany");
    }

    public function getLabel(): string {
        return 'Asteroidy';
    }

    public function fetchData(): array
    {
        return [$this->getYesterdayAsteroids()];
    }

    public function draw(GdImage $img, array $data, Bounds $bounds): void
    {
        $cy = $bounds->y + 55;
        $asteroids = $data[0] ?? [];
        $count = count($asteroids);

        GdImageHelper::centerText($img, $this->conjugateHeader($count), $cy, 28, '#FFFFFF', FONT_BOLD);

        if ($count === 0) {
            return;
        }

        $closest = $this->findClosestApproach($asteroids);
        if ($closest === null) {
            return;
        }

        // ── Nazwa najbliższej asteroidy ──────────────────────────────────────
        $cy += 170;
        GdImageHelper::drawText($img, 'Najbardziej zbliżyła się ' . $closest['name'] . ' na odległość:', $bounds->x + 60, $cy, 20, '#9AA5BD', FONT);

        // ── Odległość + % odl. do Księżyca ───────────────────────────────────
        $cy += 50;
        $distanceText = number_format($closest['distance_km'], 0, ',', ' ') . ' km';
        $moonPercent = $closest['distance_km'] / 384400 * 100;

        GdImageHelper::drawText($img, $distanceText , $bounds->x + 60, $cy, 24, '#FFFFFF', FONT_BOLD);

        // ── Pasek proporcji Ziemia -> Księżyc (lub mnożnik, gdy poza skalą) ──
        $cy += 55;
        $barWidth = $bounds->width - 120;
        $iconSize = 24;
        $barColor = $moonPercent <= 100 ? '#4FD1C5' : '#F87171';

        if ($moonPercent <= 300) {
            $scaleMax = 300;
            $fillPercent = min($moonPercent, $scaleMax) / $scaleMax * 100;
            $moonMarkerX = (int) round($bounds->x + 60 + $barWidth * (100 / $scaleMax));

            GdImageHelper::drawProgressBar($img, $bounds->x + 60, $cy, $barWidth, 22, $fillPercent, $barColor, '#1E2A3A');

            // znacznik pozycji Księżyca na skali
            imagefilledrectangle($img, $moonMarkerX, $cy - 4, $moonMarkerX + 3, $cy + 26, GdImageHelper::hexToGdColor($img, '#FFFFFF'));

            GdImageHelper::drawEmojiIcon($img, '1f30d', $bounds->x + 60, $cy + 32, $iconSize);
            GdImageHelper::drawEmojiIcon($img, '1f315', $moonMarkerX - $iconSize + 15, $cy + 32, $iconSize);

            GdImageHelper::drawText($img, 'Ziemia', $bounds->x + 60 + $iconSize + 8, $cy + 36, 16, '#8894A8', FONT);
            GdImageHelper::drawText($img, 'Księżyc', $moonMarkerX + 20, $cy + 36, 16, '#8894A8', FONT);

            $cy += 90;
        } else {
            $multiplier = number_format($moonPercent / 100, 1, ',', ' ');
            GdImageHelper::drawText(
                $img,
                "Na szczęście to aż {$multiplier}× dalej niż Księżyc",
                $bounds->x + 60,
                $cy,
                20,
                '#F87171',
                FONT_BOLD
            );
            $cy += 60;
        }

        // ── Notka o Apophis, zawinięta ────────────────────────────────────────
        $cy += 30;
        $apophisText = 'Pamiętajcie: (99942) Apophis zbliży się do Ziemi 13 kwietnia 2029 r. na 31 600 km (8,2% odl. do Księżyca)';

        GdImageHelper::drawWrappedText(
            $img,
            $apophisText,
            $bounds->x + 60,
            $cy,
            $bounds->width - 120,
            16,
            '#6B7A99',
            FONT,
            22
        );
    }

    private function getYesterdayAsteroids(): array {
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        Console::info("Pobieram dane o asteroidach z dnia: $yesterday");
        $url = sprintf(
            '%s?start_date=%s&end_date=%s&api_key=%s',
            self::API_URL,
            $yesterday,
            $yesterday,
            $_ENV['NASA_API_KEY']
        );

        try {
            $response = $this->http->get($url);
            Console::success("Pobrano dane o asteroidach z dnia: $yesterday");
        } catch (\Throwable $e) {
            Console::plugin('NeoWsPlugin: błąd pobierania danych - ' . $e->getMessage());
            return [];
        }

        return $response['near_earth_objects'][$yesterday] ?? [];
    }

    private function findClosestApproach(array $asteroids): ?array
    {
        $closest = null;

        foreach ($asteroids as $asteroid) {
            $approach = $asteroid['close_approach_data'][0] ?? null;
            $distanceKm = (float) ($approach['miss_distance']['kilometers'] ?? PHP_FLOAT_MAX);

            if ($closest === null || $distanceKm < $closest['distance_km']) {
                $closest = [
                    'name' => $asteroid['name'],
                    'distance_km' => $distanceKm,
                ];
            }
        }

        return $closest;
    }

    private function pluralizeAsteroid(int $count): string
    {
        if ($count === 1) {
            return 'asteroida';
        }

        $lastDigit = $count % 10;
        $lastTwoDigits = $count % 100;

        if ($lastDigit >= 2 && $lastDigit <= 4 && !($lastTwoDigits >= 12 && $lastTwoDigits <= 14)) {
            return 'asteroidy';
        }

        return 'asteroid';
    }
    private function conjugateHeader(int $count): string
    {
        $noun = $this->pluralizeAsteroid($count);

        $verb = match (true) {
            $count === 1 => 'odwiedziła',
            $noun === 'asteroidy' => 'odwiedziły',
            default => 'odwiedziło',
        };

        return "Wczoraj Ziemię {$verb} {$count} {$noun}";
    }
}