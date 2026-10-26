<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Photo;
use Swagnar\Igapi\Layout\Spacer;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;
use Swagnar\Igapi\Layout\WrappedText;
use RuntimeException;

class DisasterPlugin extends AbstractPlugin
{
    private const CATEGORY_LABELS = [
        'wildfires'    => 'Pożary',
        'earthquakes'  => 'Trzęsienia ziemi',
        'floods'       => 'Powodzie',
        'severeStorms' => 'Silne burze',
        'volcanoes'    => 'Wulkany',
        'landslides'   => 'Osunięcia ziemi',
        'drought'      => 'Susze',
        'snow'         => 'Ekstremalny śnieg',
        'tempExtremes' => 'Ekstremalne temperatury',
    ];

    public function __construct(private readonly JsonClient $http) {}

    public function getLabel(): string { return 'Katastrofy'; }

    public function fetchData(): array
    {
        Console::info('Pobieram aktywne zdarzenia z EONET...');

        $url = 'https://eonet.gsfc.nasa.gov/api/v3/events?' . http_build_query([
            'status' => 'open',
            'limit'  => 300,
        ]);

        $data = $this->http->get($url);
        $events = $data['events'] ?? [];

        if (empty($events)) {
            throw new RuntimeException('Brak aktywnych zdarzeń w EONET');
        }

        Console::debug('Otrzymano ' . count($events) . ' zdarzeń z EONET');

        $groups = [];
        $skipped = 0;

        foreach ($events as $event) {
            $categoryId = $event['categories'][0]['id'] ?? null;
            $title = trim(str_replace("\xc2\xa0", ' ', $event['title'] ?? '')); // usuń też &nbsp;

            if ($categoryId === null || !isset(self::CATEGORY_LABELS[$categoryId])) {
                continue;
            }

            if ($title === '' || $title === ',') {
                $skipped++;
                continue;
            }

            $score = $event['magnitudeValue'] ?? count($event['geometry'] ?? []);

            $groups[$categoryId][] = [
                'title' => $title,
                'score' => $score,
            ];
        }

        if ($skipped > 0) {
            Console::warn("Pominięto {$skipped} zdarzeń bez tytułu");
        }

        if (empty($groups)) {
            throw new RuntimeException('Brak zdarzeń w śledzonych kategoriach');
        }

        foreach ($groups as &$items) {
            usort($items, fn($a, $b) => $b['score'] <=> $a['score']);
        }
        unset($items);

        uasort($groups, fn($a, $b) => count($b) <=> count($a));

        Console::debug('Kategorie znalezione: ' . implode(', ', array_keys($groups)));

        $topCategoryId = array_key_first($groups);
        $photo = $this->fetchPhotoForEvent($topCategoryId, $groups[$topCategoryId]);

        if ($photo !== null) {
            $caption = $photo['title'] . (!empty($photo['year']) ? " ({$photo['year']})" : '');
            $photo['caption']     = $photo['title'] !== '' ? GdImageHelper::translateToPolish($caption) : null;
            $photo['description'] = $photo['description'] !== ''
                ? GdImageHelper::translateToPolish(GdImageHelper::truncateAtWordBoundary($photo['description'], 150))
                : null;
        }

        return [
            'groups' => $groups,
            'photo'  => $photo,
        ];
    }

    private function fetchPhotoForEvent(string $categoryId, array $topGroupItems): ?array
    {
        $searchTerms = [
            'wildfires'    => 'wildfire',
            'earthquakes'  => 'earthquake',
            'floods'       => 'flood',
            'severeStorms' => 'storm hurricane',
            'volcanoes'    => 'volcano eruption',
            'landslides'   => 'landslide',
            'drought'      => 'drought',
            'snow'         => 'snow storm',
            'tempExtremes' => 'heatwave',
        ];

        $genericTerm = $searchTerms[$categoryId] ?? $categoryId;
        $eventTitle = $topGroupItems[0]['title'] ?? '';

        // Wyciągnij lokalizację ze tytułu EONET, np. "Wildfire L 28, Broward, Florida" -> "Broward Florida"
        $locationHint = $this->extractLocationHint($eventTitle);

        $attempts = [
            // 1. Najbardziej precyzyjne: kategoria + konkretna lokalizacja, tylko ostatnie 7 dni
            ['query' => trim("{$genericTerm} {$locationHint}"), 'maxAgeDays' => 7],
            // 2. Sama kategoria, ostatnie 30 dni
            ['query' => $genericTerm, 'maxAgeDays' => 30],
            // 3. Sama kategoria, bez limitu dat (ostatnia deska ratunku)
            ['query' => $genericTerm, 'maxAgeDays' => null],
        ];

        foreach ($attempts as $i => $attempt) {
            Console::info("Szukam zdjęcia — próba " . ($i + 1) . ": \"{$attempt['query']}\"" .
                ($attempt['maxAgeDays'] !== null ? " (max {$attempt['maxAgeDays']} dni)" : " (bez limitu dat)"));

            $photo = $this->searchPhoto($attempt['query'], $attempt['maxAgeDays']);

            if ($photo !== null) {
                Console::success("Znaleziono zdjęcie z {$photo['year']} (\"{$photo['title']}\")");
                return $photo;
            }
        }

        Console::warn('Nie znaleziono żadnego pasującego zdjęcia po wszystkich próbach');
        return null;
    }

    private function extractLocationHint(string $eventTitle): string
    {
        // Bierzemy fragmenty po przecinkach (zwykle stan/kraj), odrzucając same nazwy własne pożaru
        $parts = array_map('trim', explode(',', $eventTitle));
        $parts = array_filter($parts, fn($p) => $p !== '' && !preg_match('/^\d+$/', $p));

        // Ostatnie 1-2 fragmenty zwykle to lokalizacja (np. "Broward", "Florida")
        return implode(' ', array_slice($parts, -2));
    }

    private function searchPhoto(string $query, ?int $maxAgeDays): ?array
    {
        try {
            $url = 'https://images-api.nasa.gov/search?' . http_build_query([
                'q'          => $query,
                'media_type' => 'image',
                'year_start' => (int) date('Y') - 1,
            ]);

            $data = $this->http->get($url);
            $items = $data['collection']['items'] ?? [];

            if (empty($items)) {
                return null;
            }

            // Posortuj po dacie malejąco (najnowsze pierwsze)
            usort($items, function ($a, $b) {
                $dateA = $a['data'][0]['date_created'] ?? '';
                $dateB = $b['data'][0]['date_created'] ?? '';
                return strcmp($dateB, $dateA);
            });

            $cutoff = $maxAgeDays !== null
                ? (new \DateTimeImmutable("-{$maxAgeDays} days"))
                : null;

            foreach ($items as $item) {
                $itemData = $item['data'][0] ?? [];
                $dateCreated = $itemData['date_created'] ?? '';

                if ($dateCreated === '') {
                    continue;
                }

                $itemDate = new \DateTimeImmutable($dateCreated);

                if ($cutoff !== null && $itemDate < $cutoff) {
                    continue; // za stare dla tej próby
                }

                $preview = $item['links'][0]['href'] ?? null;
                if ($preview === null) {
                    continue;
                }

                return [
                    'preview'     => $preview,
                    'title'       => $itemData['title'] ?? '',
                    'description' => $itemData['description'] ?? '',
                    'year'        => substr($dateCreated, 0, 4),
                ];
            }

            return null;
        } catch (\Throwable $e) {
            Console::error("Błąd wyszukiwania zdjęcia: " . $e->getMessage());
            return null;
        }
    }

    /** Maksymalna liczba kategorii na slajdzie — reszta miejsca należy do zdjęcia */
    private const MAX_CATEGORIES = 3;

    protected function content(array $data): Component
    {
        $stack = (new Stack())
            ->add(new Text('Aktywne katastrofy na świecie', size: 28, color: '#FFFFFF', font: FONT_BOLD, height: 62));

        foreach (array_slice($data['groups'], 0, self::MAX_CATEGORIES, true) as $categoryId => $items) {
            $count = count($items);
            $top   = array_slice($items, 0, 3);

            $placeNames = array_filter(array_map(
                fn($item) => mb_substr(trim($item['title']), 0, 40),
                $top
            ), fn($t) => $t !== '');

            $placesText = 'Miejsca: ' . implode(', ', $placeNames);
            $remaining  = $count - count($top);
            if ($remaining > 0) {
                $placesText .= " (+{$remaining} więcej)";
            }

            $stack->add(new Text(self::CATEGORY_LABELS[$categoryId] . ": {$count}", size: 24, color: '#FF8866', font: FONT_BOLD, align: 'center', marginTop: 8));
            $stack->add(new WrappedText($placesText, size: 18, color: '#AAAAAA', font: FONT, lineHeight: 26, marginBottom: 14));
        }

        $photo = $data['photo'];
        if ($photo === null) {
            Console::debug('Brak zdjęcia do narysowania w tym cyklu');
            return $stack;
        }

        return $stack
            ->add(new Spacer(10))
            ->add(new Photo(
                $photo['preview'],
                caption:      $photo['caption'],
                description:  $photo['description'],
                align:        'left',
                minHeight:    100,
                captionSize:  20,
                captionColor: '#FFFFFF',
            ));
    }
}
