<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Http\JsonClient;

/** Trzęsienia ziemi M4.5+ z ostatniej doby (USGS) na mapie świata Equal Earth + trzy najsilniejsze. */
class EarthquakesPlugin extends AbstractPlugin
{
    private const FEED_URL = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/4.5_day.geojson';
    private const TOP      = 3;

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("EarthquakesPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Trzęsienia ziemi'; }

    public function getCredit(): string { return 'USGS'; }

    public function fetchData(): array
    {
        Console::plugin("EarthquakesPlugin: pobieram dane z USGS...");
        $features = $this->http->get(self::FEED_URL)['features'] ?? [];

        $quakes = array_map(fn($f) => [
            'mag'   => round((float) $f['properties']['mag'], 1),
            'place' => (string) ($f['properties']['place'] ?? ''),
            'lon'   => (float) $f['geometry']['coordinates'][0],
            'lat'   => (float) $f['geometry']['coordinates'][1],
            'depth' => (float) $f['geometry']['coordinates'][2],
            'time'  => (int) intdiv((int) $f['properties']['time'], 1000),
        ], $features);
        usort($quakes, fn($a, $b) => $b['mag'] <=> $a['mag']);

        // Tłumaczymy tylko trzy opisane pod mapą — reszta to same punkty
        foreach (array_slice(array_keys($quakes), 0, self::TOP) as $i) {
            $quakes[$i]['region'] = $this->region($quakes[$i]['place']);
        }

        return ['count' => count($quakes), 'quakes' => $quakes];
    }

    /**
     * Pola dla plugins/earthquakes.json: podpis pod liczbą, znaczniki i przybliżenie dla WorldMap, wiersze tabeli.
     *   { "type": "WorldMap", "markers": "{{earthquakes.markers}}", "inset": "{{earthquakes.focus}}" }
     */
    public function present(array $data): array
    {
        $quakes = $data['quakes'];
        return [
            'summary' => $this->pluralize($data['count']) . ' o sile M4,5 lub większej',
            'markers' => $this->markers($quakes),
            'focus'   => $this->focus($quakes),
            'top'     => $this->top($quakes),
        ] + $data;
    }

    /** Najsilniejsze trzęsienie — przybliżenie jego rejonu w rogu mapy. */
    private function focus(array $quakes): ?array
    {
        $q = $quakes[0] ?? null;
        return $q === null ? null : ['lon' => $q['lon'], 'lat' => $q['lat'], 'label' => $q['region'] ?? $q['place']];
    }

    /** Znaczniki dla WorldMap — trzy najsilniejsze z numerami (kolejność: od najsilniejszego). */
    private function markers(array $quakes): array
    {
        $markers = [];
        foreach ($quakes as $i => $q) {
            $markers[] = [
                'lon'    => $q['lon'],
                'lat'    => $q['lat'],
                'radius' => $this->radius($q['mag']),
                'color'  => $this->color($q['mag']),
                'label'  => $i < self::TOP ? (string) ($i + 1) : null,
            ];
        }
        return $markers;
    }

    /**
     * Wiersze tabeli pod mapą: komórki (nr, magnituda, region, głębokość) + gotowy tekst w jednej linii.
     * @return list<array{cells: list<string>, text: string, color: string}>
     */
    private function top(array $quakes): array
    {
        $rows = [];
        foreach (array_slice($quakes, 0, self::TOP) as $i => $q) {
            $cells  = [
                ($i + 1) . '.',
                'M' . number_format($q['mag'], 1, ',', ''),
                $q['region'] ?? $q['place'],
                number_format($q['depth'], 0, ',', ' ') . ' km pod ziemią',
            ];
            $rows[] = ['cells' => $cells, 'text' => implode('  ', $cells), 'color' => $this->color($q['mag'])];
        }
        return $rows;
    }

    /** "30 km WSW of Lata, Solomon Islands" → "Wyspy Salomona"; opisy bez przecinka ("Mid-Atlantic Ridge") w całości. */
    private function region(string $place): string
    {
        $region = trim(str_contains($place, ', ') ? substr($place, strrpos($place, ', ') + 2) : preg_replace('/^\d+\s*km\s+\w+\s+of\s+/i', '', $place));
        if ($region === '') {
            return $place;
        }

        try {
            $translated = GdImageHelper::translateToPolish($region);
            return GdImageHelper::truncateAtWordBoundary($translated !== '' ? $translated : $region, 40);
        } catch (\Throwable) {
            return $region;
        }
    }

    /** Promień rośnie szybciej niż magnituda (skala jest logarytmiczna), ale z limitem, żeby M8 nie zakryło kontynentu. */
    private function radius(float $mag): float
    {
        return min(34.0, 3.5 + (max(0.0, $mag - 4.5) ** 1.6) * 8);
    }

    private function color(float $mag): string
    {
        return match (true) {
            $mag >= 6.0 => '#F87171',
            $mag >= 5.0 => '#FB923C',
            default     => '#FBBF24',
        };
    }

    private function pluralize(int $n): string
    {
        $mod10  = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $n === 1 => 'trzęsienie',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'trzęsienia',
            default => 'trzęsień',
        };
    }
}
