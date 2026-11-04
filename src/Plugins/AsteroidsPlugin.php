<?php

namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Plugins\MoonProximityBar;

use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;
use Swagnar\Igapi\Layout\Spacer;

class AsteroidsPlugin extends AbstractPlugin {
    private const API_URL = 'https://api.nasa.gov/neo/rest/v1/feed';

    private const SPACE_FACTS = [
    'Pamiętajcie: (99942) Apophis zbliży się do Ziemi 13 kwietnia 2029 r. na około 31 600 km od powierzchni Ziemi.',
    'Znamy już ponad 95% asteroid większych niż 1 km. (w okolicy)',
    'Usunięto Herobrine',
    ];

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
        return [
            'asteroids' => $this->getYesterdayAsteroids(),
        ];
    }

    protected function content(array $data): Component
    {
        $asteroids = $data['asteroids'] ?? [];
        $count = count($asteroids);

        $stack = (new Stack())
            ->add(new Text($this->conjugateHeader($count), size: 28, color: '#FFFFFF', font: FONT_BOLD, height: 40));

        $closest = $count > 0 ? $this->findClosestApproach($asteroids) : null;
        if ($closest === null) {
            return $stack;
        }

        $distanceText = number_format($closest['distance_km'], 0, ',', ' ') . ' km';
        $moonPercent  = $closest['distance_km'] / 384400 * 100;

        return $stack
            ->add(new Text('Najbardziej zbliżyła się ' . $closest['name'] . ' na odległość:', size: 22, color: '#AAAAAA', font: FONT, height: 38))
            ->add(new Text($distanceText, size: 32, color: '#88CCFF', font: FONT_BOLD, height: 55))
            ->add(new Spacer(10))
            ->add(new MoonProximityBar($moonPercent, indent: 0));
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