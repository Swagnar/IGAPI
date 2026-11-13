<?php
namespace Swagnar\Igapi\Data\Sources;

use Swagnar\Igapi\Data\DataSource;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Theme;
use Swagnar\Igapi\Layout\VerticalSplit;
use RuntimeException;

/**
 * Losowe zdjęcie z Curiosity z ostatniego roku (NASA Image Library) z podpisem po polsku.
 * 'size' — ile ćwiartek slajdu potrzebuje plugin, żeby zdjęcie weszło na pełną szerokość karty
 * (plugins/curiosity.json ma "size": "{{curiosity.size}}"): poziome zwykle 2–3, pionowe 4.
 */
final class CuriosityPhotoSource implements DataSource
{
    private const API_URL = 'https://images-api.nasa.gov/search';

    /** Wysokość bloków pluginu poza zdjęciem: nagłówek 60 + odstęp 20, odstęp 6 i podpis (1 linia, 20 px) 38 */
    private const CHROME = 60 + 20 + 6 + 38;

    public function __construct(private readonly JsonClient $http) {}

    public function id(): string { return 'curiosity'; }

    public function label(): string { return 'NASA Image Library — Curiosity'; }

    public function credit(): ?string { return 'NASA'; }

    public function describe(): array
    {
        return [
            'endpoint' => self::API_URL,
            'params'   => $this->params(),
            'docs'     => 'https://images.nasa.gov/docs/images.nasa.gov_api_docs.pdf',
        ];
    }

    public function maxFallbackAge(): ?int { return null; }

    public function fetch(): array
    {
        Console::info("Pobieram losowe zdjęcie z Curiosity...");
        $data  = $this->http->get(self::API_URL . '?' . http_build_query($this->params()));
        $items = $data['collection']['items'] ?? [];

        if (empty($items)) {
            throw new RuntimeException('Brak zdjęć Curiosity w images-api.nasa.gov');
        }
        $item    = $items[array_rand($items)];
        $preview = $item['links'][0]['href'] ?? null;

        if ($preview === null) {
            throw new RuntimeException('Brak miniaturki dla znalezionego zdjęcia');
        }
        Console::success("Pobrano losowe zdjęcie z Curiosity");

        $title = $item['data'][0]['title'] ?? '';

        return [
            'title'   => $title,
            'caption' => $title === '' ? null : $this->caption($title),
            'preview' => $preview,
            'size'    => $this->size($preview),
        ];
    }

    /** Najmniejszy slot (1–4 ćwiartki), w którym zdjęcie mieści się na całą szerokość karty; bez zdjęcia — 3. */
    private function size(string $url): int
    {
        $photo = GdImageHelper::loadRemoteImage($url);
        if ($photo === null) {
            return 3;
        }
        [$side, $top, $bottom] = Theme::PADDING;
        $width  = Slide::CONTENT_WIDTH - 2 * $side;
        $needed = (int) ceil($width * imagesy($photo) / imagesx($photo)) + self::CHROME + $top + $bottom;
        imagedestroy($photo);

        for ($size = 1; $size < VerticalSplit::QUARTERS; $size++) {
            if (VerticalSplit::slot($size, Slide::CONTENT_HEIGHT, 40) >= $needed) {
                return $size;
            }
        }
        return VerticalSplit::QUARTERS;
    }

    private function params(): array
    {
        return [
            'q'          => 'curiosity mars rover',
            'media_type' => 'image',
            'year_start' => (int) date('Y') - 1,
        ];
    }

    private function caption(string $title): string
    {
        $caption = GdImageHelper::translateToPolish($title);
        // Tłumacz potrafi przełożyć nazwę łazika dosłownie ("HiRISE obserwuje ciekawość...")
        $caption = preg_replace('/(?<!\p{L})[Cc]iekawoś(?:ć|ci|cią)(?!\p{L})/u', 'Curiosity', $caption);
        return GdImageHelper::truncateAtWordBoundary($caption, 70);
    }
}
