<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Wszystkie loty nad Polską z poprzedniej doby — publikacja jest o 6:00, więc pokazujemy zamknięty dzień, a nie
 * przypadkowy moment. Live API adsb.fi (open data: do użytku osobistego, niekomercyjnego, 1 zapytanie/s, z podaniem
 * źródła) nie ma historii, dlatego dzień zbiera osobny CRON: collect-planes.php co 5 minut woła collect(), które
 * dopisuje próbkę (samoloty w oknie wokół Polski) do cache/planes/<data>.jsonl.
 * fetchData() składa z wczorajszego pliku loty: próbki jednego samolotu z przerwą > gap() to osobne loty, a do
 * wyniku trafiają tylko te, które choć raz były nad Polską. Kategorie: wojskowe (`dbFlags` z bazy adsb.fi), rządowe
 * (`PLF1…`, `SAM`), cargo (prefiksy przewoźników), śmigłowce. Wygląd — HeatMap Polski ze śladami i wykres
 * godzinowy w plugins/planes.json.
 *
 * Bez wczorajszego pliku albo przy dziurawym (kolektor nie chodził) plugin się pomija — w puli losuje się inny blok.
 */
class PlanesPlugin extends AbstractPlugin
{
    private const POINT_URL = 'https://opendata.adsb.fi/api/v3/lat/52.0/lon/19.2/dist/250';
    private const DIR       = ROOT_DIR . '/cache/planes';
    private const KEEP_DAYS = 7;

    /** Okno zapisu (trochę szersze niż widok mapy, żeby ślady wychodziły za granicę) i siatka-maska Polski */
    private const WINDOW = [12.5, 25.7, 48.3, 55.6];   // lonMin, lonMax, latMin, latMax
    private const VIEW   = [13.6, 24.6, 48.9, 55.0];
    private const CELL   = 0.05;

    /** Ile doby musi pokrywać plik, żeby liczby miały sens */
    private const MIN_COVERAGE = 0.8;

    /** Flagi samolotu w próbce */
    private const MIL  = 1;
    private const HELI = 2;

    /** Kategorie: klucz → [nazwa, kolor, wyróżniona] — kolejność = kolejność rysowania (wyróżnione na wierzchu) */
    public const CATEGORIES = [
        'civil' => ['Pasażerskie i inne', '#7DD3FC', false],
        'cargo' => ['Cargo', '#A78BFA', false],
        'heli'  => ['Śmigłowce', '#34D399', false],
        'gov'   => ['Rządowe', '#FBBF24', true],
        'mil'   => ['Wojskowe', '#F87171', true],
    ];

    /** Znaki wywoławcze przewoźników cargo (prefiks ICAO) */
    private const CARGO = ['FDX', 'UPS', 'DHK', 'DHX', 'BCS', 'CLX', 'GTI', 'ABW', 'CKS', 'NCA', 'MPH', 'TAY', 'SWN', 'BOX', 'GEC', 'CAO', 'CSS', 'QAF', 'ICV', 'RCH'];

    /** Loty rządowe / VIP: polskie samoloty z 1. Bazy Lotnictwa Transportowego (PLF1xx) i amerykańskie SAM */
    private const GOV = ['PLF1', 'SAM'];

    private const DAYS   = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
    private const MONTHS = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("PlanesPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Samoloty nad Polską'; }

    public function getCredit(): string { return 'adsb.fi'; }

    public function canSkip(): bool { return true; }

    // ------------------------------------------------------------------------------------------ zbieranie (CRON)

    /**
     * Jedna próbka: samoloty w powietrzu w oknie wokół Polski, dopisana jako linia JSON do pliku dnia
     * {"t": unix, "ac": {hex: [lon, lat, kurs, znak, typ, flagi]}}. Zwraca liczbę zapisanych samolotów.
     */
    public function collect(): int
    {
        [$lonMin, $lonMax, $latMin, $latMax] = self::WINDOW;
        $ac = [];
        foreach ($this->http->get(self::POINT_URL)['ac'] ?? [] as $a) {
            $lon = (float) ($a['lon'] ?? 999);
            $lat = (float) ($a['lat'] ?? 999);
            if (($a['alt_baro'] ?? 'ground') === 'ground' || $lon < $lonMin || $lon > $lonMax || $lat < $latMin || $lat > $latMax) {
                continue;
            }
            $flags = (((int) ($a['dbFlags'] ?? 0) & 1) ? self::MIL : 0) | (($a['category'] ?? '') === 'A7' ? self::HELI : 0);
            $ac[strtolower((string) $a['hex'])] = [round($lon, 3), round($lat, 3), (int) round((float) ($a['track'] ?? 0)),
                trim((string) ($a['flight'] ?? '')), (string) ($a['t'] ?? ''), $flags];
        }

        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0775, true);
        }
        file_put_contents(self::DIR . '/' . date('Y-m-d') . '.jsonl', json_encode(['t' => time(), 'ac' => $ac], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        foreach (glob(self::DIR . '/*.jsonl') ?: [] as $file) {
            if (basename($file, '.jsonl') < date('Y-m-d', strtotime('-' . self::KEEP_DAYS . ' days'))) {
                unlink($file);
            }
        }
        return count($ac);
    }

    // ------------------------------------------------------------------------------------------ wczorajsza doba

    public function fetchData(): array
    {
        $day     = date('Y-m-d', strtotime('yesterday'));
        $samples = $this->samples(self::DIR . "/{$day}.jsonl");
        Console::plugin(sprintf('PlanesPlugin: %d próbek z %s', count($samples), $day));
        if (count($samples) < 2) {
            Console::warn('PlanesPlugin: brak próbek — czy chodzi collect-planes.php?');
            return $this->skip("brak danych o lotach z {$day}");
        }

        $interval = $this->interval($samples);
        $coverage = min(1, count($samples) * $interval / 86400);
        if ($coverage < self::MIN_COVERAGE) {
            return $this->skip(sprintf('próbki z %s pokrywają tylko %d%% doby', $day, $coverage * 100));
        }

        $mask    = $this->polandMask();
        $gap     = max(3 * $interval, 1200);
        $open    = [];   // hex → lot w trakcie
        $flights = [];
        $hours   = array_fill(0, 24, []);
        $busiest = ['count' => 0, 't' => 0];
        foreach ($samples as $sample) {
            $t     = $sample['t'];
            $hour  = (int) date('G', $t);
            $above = 0;
            foreach ($sample['ac'] as $hex => [$lon, $lat, $track, $callsign, $type, $flags]) {
                $hex = (string) $hex;
                if (isset($open[$hex]) && $t - $open[$hex]['end'] > $gap) {
                    $this->close($flights, $open[$hex]);
                    unset($open[$hex]);
                }
                $f = $open[$hex] ?? ['hex' => $hex, 'callsign' => '', 'type' => '', 'flags' => 0, 'start' => $t, 'end' => $t, 'path' => [], 'pl' => false, 'id' => count($flights) . $hex];
                $f['end']       = $t;
                $f['callsign']  = $callsign ?: $f['callsign'];
                $f['type']      = $type ?: $f['type'];
                $f['flags']    |= $flags;
                $f['path'][]    = [$lon, $lat, $track];
                if (isset($mask[(int) floor(($lat - self::WINDOW[2]) / self::CELL)][(int) floor(($lon - self::WINDOW[0]) / self::CELL)])) {
                    $f['pl'] = true;
                    $above++;
                    $hours[$hour][$f['id']] = true;
                }
                $open[$hex] = $f;
            }
            if ($above > $busiest['count']) {
                $busiest = ['count' => $above, 't' => $t];
            }
        }
        foreach ($open as $f) {
            $this->close($flights, $f);
        }
        if (!$flights) {
            return $this->skip("w próbkach z {$day} nie ma lotów nad Polską");
        }

        Console::success(sprintf('PlanesPlugin: %d lotów nad Polską w %s (próbka co %d min)', count($flights), $day, round($interval / 60)));
        return [
            'day'      => $day,
            'interval' => $interval,
            'coverage' => round($coverage, 2),
            'flights'  => $flights,
            'hours'    => array_map('count', $hours),
            'busiest'  => $busiest,
        ];
    }

    /** @return list<array{t: int, ac: array<string, list<mixed>>}> */
    private function samples(string $file): array
    {
        $samples = [];
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $sample = json_decode($line, true);
            if (isset($sample['t'], $sample['ac'])) {
                $samples[] = $sample;
            }
        }
        usort($samples, fn($a, $b) => $a['t'] <=> $b['t']);
        return $samples;
    }

    /** Typowy odstęp między próbkami (mediana) — od niego zależy, kiedy przerwa w śladzie oznacza nowy lot. */
    private function interval(array $samples): int
    {
        $diffs = [];
        for ($i = 1; $i < count($samples); $i++) {
            $diffs[] = $samples[$i]['t'] - $samples[$i - 1]['t'];
        }
        sort($diffs);
        return max(60, $diffs[intdiv(count($diffs), 2)]);
    }

    /** Lot trafia do wyniku, jeśli choć jedna próbka była nad Polską; kategoria i ślad liczone od razu. */
    private function close(array &$flights, array $f): void
    {
        if (!$f['pl']) {
            return;
        }
        $flights[] = [
            'hex'      => $f['hex'],
            'callsign' => $f['callsign'],
            'type'     => $f['type'],
            'category' => $this->category($f['callsign'], $f['flags']),
            'start'    => $f['start'],
            'end'      => $f['end'],
            // Pojedyncza próbka to jeszcze nie linia — odcinek wzdłuż kursu
            'path'     => count($f['path']) > 1 ? array_map(fn($p) => [$p[0], $p[1]], $f['path']) : $this->stub($f['path'][0]),
        ];
    }

    /** Siatka komórek CELL° wewnątrz granicy Polski: [wiersz][kolumna] => true. */
    private function polandMask(): array
    {
        $ring = array_map(fn($p) => [(float) $p[1], (float) $p[0]], require ROOT_DIR . '/geo/poland-border.php');
        [$lonMin, $lonMax, $latMin, $latMax] = self::WINDOW;
        $mask = [];
        for ($r = 0; $latMin + $r * self::CELL < $latMax; $r++) {
            for ($c = 0; $lonMin + $c * self::CELL < $lonMax; $c++) {
                if ($this->inside($lonMin + ($c + 0.5) * self::CELL, $latMin + ($r + 0.5) * self::CELL, $ring)) {
                    $mask[$r][$c] = true;
                }
            }
        }
        return $mask;
    }

    private function category(string $callsign, int $flags): string
    {
        $callsign = strtoupper($callsign);
        foreach (self::GOV as $prefix) {
            if (str_starts_with($callsign, $prefix)) {
                return 'gov';
            }
        }
        if ($flags & self::MIL) {
            return 'mil';
        }
        if ($flags & self::HELI) {
            return 'heli';
        }
        return in_array(substr($callsign, 0, 3), self::CARGO, true) ? 'cargo' : 'civil';
    }

    /** Odcinek ±30 km wzdłuż kursu — dla lotu złapanego tylko w jednej próbce. */
    private function stub(array $p): array
    {
        [$lon, $lat, $track] = $p;
        $rad = deg2rad($track);
        $dx  = 30 * sin($rad) / (111.32 * cos(deg2rad($lat)));
        $dy  = 30 * cos($rad) / 110.57;
        return [[round($lon - $dx, 3), round($lat - $dy, 3)], [round($lon + $dx, 3), round($lat + $dy, 3)]];
    }

    /** Punkt w wielokącie (ray casting) — kontur [[lon, lat], …]. */
    private function inside(float $lon, float $lat, array $ring): bool
    {
        $in = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat) && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $in = !$in;
            }
        }
        return $in;
    }

    // ------------------------------------------------------------------------------------------ prezentacja

    /** Pola dla plugins/planes.json: liczby, legenda, wyróżnione loty, ślady na mapę i ruch godzina po godzinie. */
    public function present(array $data): array
    {
        $flights = $data['flights'];
        $counts  = array_fill_keys(array_keys(self::CATEGORIES), 0);
        foreach ($flights as $f) {
            $counts[$f['category']]++;
        }

        // Ślady: zwykłe loty mocno przygaszone (jest ich tysiące — gęstość sama rysuje korytarze), wyróżnione na wierzchu
        $paths = [];
        foreach (array_keys(self::CATEGORIES) as $cat) {
            [, $color, $lit] = self::CATEGORIES[$cat];
            foreach ($flights as $f) {
                if ($f['category'] === $cat) {
                    $paths[] = ['color' => $color, 'core' => $lit ? $color : null, 'alpha' => 112, 'width' => 1,
                        'points' => array_map(fn($pt) => [$pt[0], $pt[1], $lit ? 1 : 0], $f['path'])];
                }
            }
        }

        $legend = [];
        foreach (self::CATEGORIES as $cat => [$name, $color]) {
            if ($counts[$cat] > 0) {
                $legend[] = ['cells' => ['●', $name, $this->number($counts[$cat])], 'color' => $color];
            }
        }

        $notable = [];
        foreach ($flights as $f) {
            if (in_array($f['category'], ['mil', 'gov'], true)) {
                $notable[$f['callsign'] ?: $f['hex']] = ($f['callsign'] ?: 'bez znaku') . ($f['type'] ? " ({$f['type']})" : '');
            }
        }

        $hours = $data['hours'];
        $peak  = array_keys($hours, max($hours))[0];
        $bars  = [];
        foreach ($hours as $h => $n) {
            $bars[] = ['label' => sprintf('%d:00', $h), 'value' => $n, 'tick' => $h % 6 === 0, 'color' => $h === $peak ? '#7DD3FC' : '#2E5A85'];
        }

        $day   = strtotime($data['day']);
        $total = count($flights);
        return [
            'countText'   => $this->number($total) . ' ' . $this->plural($total, 'lot', 'loty', 'lotów'),
            'subline'     => sprintf('%s, %d %s · każda linia to jeden lot', self::DAYS[(int) date('w', $day)], (int) date('j', $day), self::MONTHS[(int) date('n', $day) - 1]),
            'legend'      => $legend,
            'notableText' => $notable ? 'Wojskowe i rządowe: ' . implode(', ', array_slice($notable, 0, 6)) . (count($notable) > 6 ? ' i ' . (count($notable) - 6) . ' innych' : '') : null,
            'peakText'    => sprintf('Najwięcej naraz: %s o %s · najtłoczniejsza godzina %d:00', $this->number($data['busiest']['count']), date('H:i', $data['busiest']['t']), $peak),
            'hourBars'    => $bars,
            'gapNote'     => $data['coverage'] < 0.98 ? sprintf('Próbki pokrywają %d%% doby', $data['coverage'] * 100) : null,
            'paths'       => $paths,
            'view'        => self::VIEW,
        ] + $data;
    }

    private function number(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    /** 1 lot, 2–4 loty, 5–21 lotów, 22 loty… */
    private function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }
        return $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? $few : $many;
    }
}
