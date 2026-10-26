<?php

namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Plugins\MoonProximityBar;

use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;
use Swagnar\Igapi\Layout\WrappedText;
use Swagnar\Igapi\Layout\Spacer;
use Swagnar\Igapi\Layout\Photo;

class AsteroidsPlugin extends AbstractPlugin {
    private const API_URL = 'https://api.nasa.gov/neo/rest/v1/feed';

    private const WOW_PHOTO_QUERIES = [
        'asteroid Bennu OSIRIS-REx',
        'asteroid Ryugu Hayabusa2',
        'meteor fireball atmosphere',
        'Oumuamua interstellar asteroid',
        'DART mission asteroid impact',
        'Chelyabinsk meteor',
        'asteroid Vesta Dawn spacecraft',
        'asteroid Ceres surface',
        'impact crater asteroid',
        'asteroid belt illustration',
        'asteroid Itokawa Hayabusa',
        'meteorite',
    ];

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
            'photo'     => $this->fetchWowPhoto(),
        ];
    }

    protected function content(array $data): Component
    {
        $asteroids = $data['asteroids'] ?? [];
        $photo = $data['photo'] ?? null;
        $count = count($asteroids);

        $stack = (new Stack())
            ->add(new Text($this->conjugateHeader($count), size: 28, color: '#FFFFFF', font: FONT_BOLD, align: 'center', height: 35));

        if ($count === 0) {
            $stack->add(new Spacer(60));
            $stack->add($this->photoComponent($photo));
            return $stack;
        }

        $closest = $this->findClosestApproach($asteroids);
        if ($closest === null) {
            return $stack;
        }

        $distanceText = number_format($closest['distance_km'], 0, ',', ' ') . ' km';
        $moonPercent  = $closest['distance_km'] / 384400 * 100;
 
        $stack->add(new Spacer(100));
        $stack->add(new Text('Najbardziej zbliżyła się ' . $closest['name'] . ' na odległość:', size: 20, color: '#9AA5BD', font: FONT, indent: 60, height: 50));
        $stack->add(new Text($distanceText, size: 24, color: '#FFFFFF', font: FONT_BOLD, indent: 60, height: 55));
        $stack->add(new MoonProximityBar($moonPercent, indent: 60));
        $stack->add(new Spacer(10));
        //$stack->add(new WrappedText(
        //    text: $this->pickSpaceFact(),
        //    size: 16, color: '#6B7A99', font: FONT, indent: 60, widthPadding: 120, lineHeight: 22,
        //));
        $stack->add(new Spacer(30));
        $stack->add($this->photoComponent($photo));
 
        return $stack;
    }

    private function photoComponent(?array $photo): Photo
    {
        $caption = null;
        if (!empty($photo['title'])) {
            $caption = $photo['title'] . (!empty($photo['year']) ? " ({$photo['year']})" : '');
        }

        return new Photo($photo['preview'] ?? null, caption: $caption, indent: 60, widthPadding: 120);
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
    private function fetchWowPhoto(): ?array
    {
        Console::info("Szukam efektownego zdjęcia asteroidy/meteoru...");

        // Seed zależny od dnia — to samo zdjęcie utrzymuje się przez cały dzień,
        // ale każdego dnia zaczynamy przeszukiwanie od innego miejsca w liście zapytań.
        $seed = (int) date('Ymd');
        mt_srand($seed);
        $startIndex = mt_rand(0, count(self::WOW_PHOTO_QUERIES) - 1);
        mt_srand(); // reset seeda, żeby nie wpłynąć na inne losowania w tym requeście

        $queries = self::WOW_PHOTO_QUERIES;
        $ordered = array_merge(
            array_slice($queries, $startIndex),
            array_slice($queries, 0, $startIndex)
        );

        foreach ($ordered as $query) {
            $photo = $this->searchPhoto($query);
            if ($photo !== null) {
                Console::success("Znaleziono zdjęcie: \"{$photo['title']}\" ({$photo['year']}) dla zapytania \"{$query}\"");
                return $photo;
            }
        }

        Console::warn('Nie znaleziono żadnego zdjęcia asteroidy po wszystkich próbach');
        return null;
    }
    private function searchPhoto(string $query): ?array
    {
        try {
            $url = 'https://images-api.nasa.gov/search?' . http_build_query([
                'q'          => $query,
                'media_type' => 'image',
            ]);

            $data = $this->http->get($url);
            $items = $data['collection']['items'] ?? [];

            if (empty($items)) {
                return null;
            }

            // Losujemy jedno z kilku pierwszych (najtrafniejszych) wyników — dla urozmaicenia
            $top = array_slice($items, 0, min(5, count($items)));
            $item = $top[array_rand($top)];

            $itemData = $item['data'][0] ?? [];
            $preview = $item['links'][0]['href'] ?? null;

            if ($preview === null) {
                return null;
            }

            $dateCreated = $itemData['date_created'] ?? '';

            return [
                'preview'     => $preview,
                'title'       => $itemData['title'] ?? '',
                'description' => $itemData['description'] ?? '',
                'year'        => $dateCreated !== '' ? substr($dateCreated, 0, 4) : '',
            ];
        } catch (\Throwable $e) {
            Console::error("Błąd wyszukiwania zdjęcia asteroidy: " . $e->getMessage());
            return null;
        }
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