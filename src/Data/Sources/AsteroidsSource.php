<?php
namespace Swagnar\Igapi\Data\Sources;

use Swagnar\Igapi\Data\DataSource;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/** Asteroidy, które wczoraj minęły Ziemię (NASA NeoWs): liczba i najbliższe przejście. */
final class AsteroidsSource implements DataSource
{
    private const API_URL = 'https://api.nasa.gov/neo/rest/v1/feed';
    private const MOON_KM = 384400;

    public function __construct(private readonly JsonClient $http) {}

    public function id(): string { return 'asteroids'; }

    public function label(): string { return 'NASA NeoWs — asteroidy'; }

    public function credit(): ?string { return 'NASA'; }

    public function describe(): array
    {
        return [
            'endpoint' => self::API_URL,
            'params'   => ['start_date' => 'wczoraj', 'end_date' => 'wczoraj', 'api_key' => 'NASA_API_KEY z .env'],
            'docs'     => 'https://api.nasa.gov/#NeoWS',
        ];
    }

    // Dane opisują „wczoraj” — po dobie byłyby już nieprawdziwe
    public function maxFallbackAge(): ?int { return 20 * 3600; }

    public function fetch(): array
    {
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        Console::info("Pobieram dane o asteroidach z dnia: $yesterday");

        $response = $this->http->get(self::API_URL . '?' . http_build_query([
            'start_date' => $yesterday,
            'end_date'   => $yesterday,
            'api_key'    => $_ENV['NASA_API_KEY'] ?? throw new RuntimeException('Brak NASA_API_KEY w .env'),
        ]));
        $asteroids = $response['near_earth_objects'][$yesterday] ?? [];
        Console::success("Pobrano dane o asteroidach z dnia: $yesterday");

        $count   = count($asteroids);
        $closest = $this->findClosestApproach($asteroids);

        return [
            'date'    => $yesterday,
            'count'   => $count,
            'header'  => $this->conjugateHeader($count),
            'closest' => $closest === null ? null : [
                'name'         => $closest['name'],
                'distanceKm'   => $closest['distance_km'],
                'distanceText' => number_format($closest['distance_km'], 0, ',', ' ') . ' km',
                'moonPercent'  => $closest['distance_km'] / self::MOON_KM * 100,
            ],
        ];
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
