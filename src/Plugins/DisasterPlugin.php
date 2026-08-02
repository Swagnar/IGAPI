<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Helper\Console;
use GdImage;
use RuntimeException;

class DisasterPlugin
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

    public function draw(GdImage $img, array $data, Bounds $bounds): void
    {
        $x = $bounds->x;
        $y = $bounds->y;
        $maxWidth = $bounds->width;

        GdImageHelper::drawText($img, 'Aktywne katastrofy na świecie', $x, $y, 28, '#FFFFFF', FONT_BOLD);
        $y += 50;

        foreach ($data['groups'] as $categoryId => $items) {
            $label = self::CATEGORY_LABELS[$categoryId];
            $count = count($items);

            GdImageHelper::centerText($img, "{$label}: {$count}", $y, 24, '#FF8866', FONT_BOLD);
            $y += 32;

            $top = array_slice($items, 0, 3);
            $placeNames = array_filter(array_map(
                fn($item) => mb_substr(trim($item['title']), 0, 40),
                $top
            ), fn($t) => $t !== '');

            $placesText = 'Miejsca: ' . implode(', ', $placeNames);

            $remaining = $count - count($top);
            if ($remaining > 0) {
                $placesText .= " (+{$remaining} więcej)";
            }

            $y = GdImageHelper::drawWrappedText($img, $placesText, $x, $y, $maxWidth, 18, '#AAAAAA', FONT, 26);
            $y += 14;
        }

        if ($data['photo'] !== null) {
            $y = $this->drawPhoto($img, $data['photo'], $x, $y, $bounds->width, $bounds->y + $bounds->height - $y);
        } else {
            Console::debug('Brak zdjęcia do narysowania w tym cyklu');
        }
    }

    private function drawPhoto(GdImage $img, array $photo, int $x, int $y, int $maxW, int $maxH): int
    {
        if ($maxH < 100) {
            Console::warn('Za mało miejsca na zdjęcie w bounds — pomijam rysowanie');
            return $y;
        }

        $context = stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $imageData = @file_get_contents($photo['preview'], false, $context);

        if ($imageData === false) {
            Console::error("Nie udało się pobrać obrazka: {$photo['preview']}");
            return $y;
        }

        $photoImg = imagecreatefromstring($imageData);
        if ($photoImg === false) {
            Console::error("Nieprawidłowy format obrazka: {$photo['preview']}");
            return $y;
        }

        $srcW = imagesx($photoImg);
        $srcH = imagesy($photoImg);

        $captionSpace = 60;
        $imgMaxH = $maxH - $captionSpace;

        $scale = min($maxW / $srcW, $imgMaxH / $srcH);
        $drawW = (int) ($srcW * $scale);
        $drawH = (int) ($srcH * $scale);

        imagecopyresampled($img, $photoImg, $x, $y, 0, 0, $drawW, $drawH, $srcW, $srcH);
        imagedestroy($photoImg);

        $y += $drawH + 15;

        if (!empty($photo['title'])) {
            $captionTitle = $photo['title'];
            if (!empty($photo['year'])) {
                $captionTitle .= " ({$photo['year']})";
            }

            $translatedTitle = GdImageHelper::translateToPolish($captionTitle);
            $y = GdImageHelper::drawWrappedText($img, $translatedTitle, $x, $y, $maxW, 20, '#FFFFFF', FONT_BOLD, 26);
        }

        if (!empty($photo['description'])) {
            $shortDesc = GdImageHelper::truncateAtWordBoundary($photo['description'], 150);
            $translatedDesc = GdImageHelper::translateToPolish($shortDesc);
            $y = GdImageHelper::drawWrappedText($img, $translatedDesc, $x, $y, $maxW, 16, '#888888', FONT, 22);
        }

        Console::success('Zdjęcie narysowane pomyślnie');

        return $y;
    }
}