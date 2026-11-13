<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Cykl słoneczny: miesięczna liczba plam na Słońcu (NOAA SWPC, dane SILSO) od minimum cyklu 24 do dziś, prognoza
 * NOAA do końca cyklu 25 i gdzie jesteśmy — szczyt cyklu liczony z wygładzonej (13-miesięcznej) liczby plam,
 * która ma ~6 miesięcy opóźnienia. Wygląd — ColumnChart w plugins/solarCycle.json.
 */
class SolarCyclePlugin extends AbstractPlugin
{
    private const OBSERVED_URL  = 'https://services.swpc.noaa.gov/json/solar-cycle/observed-solar-cycle-indices.json';
    private const PREDICTED_URL = 'https://services.swpc.noaa.gov/json/solar-cycle/predicted-solar-cycle.json';

    /** Wykres od minimum cyklu 24 */
    private const FROM = '2008-12';

    /** Cykle: numer → [początek (minimum), koniec] — do szukania szczytów */
    private const CYCLES = [24 => ['2008-12', '2019-11'], 25 => ['2019-12', '2031-12']];

    private const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("SolarCyclePlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Cykl słoneczny'; }

    public function getCredit(): string { return 'NOAA SWPC'; }

    public function fetchData(): array
    {
        Console::plugin("SolarCyclePlugin: pobieram liczbę plam słonecznych z NOAA SWPC...");

        // Miesiąc → [liczba plam, wygładzona albo null] (−1 w danych = brak)
        $observed = [];
        foreach ($this->http->get(self::OBSERVED_URL) as $row) {
            $month = (string) ($row['time-tag'] ?? '');
            if ($month >= self::FROM && isset($row['ssn']) && $row['ssn'] >= 0) {
                $observed[$month] = [(float) $row['ssn'], ($row['smoothed_ssn'] ?? -1) >= 0 ? (float) $row['smoothed_ssn'] : null];
            }
        }
        if (!$observed) {
            throw new RuntimeException('NOAA SWPC: brak obserwacji plam słonecznych');
        }
        ksort($observed);
        $last = array_key_last($observed);

        // Prognoza: tylko miesiące po ostatniej obserwacji
        $predicted = [];
        foreach ($this->http->get(self::PREDICTED_URL) as $row) {
            $month = (string) ($row['time-tag'] ?? '');
            if ($month > $last && isset($row['predicted_ssn'])) {
                $predicted[$month] = [(float) $row['predicted_ssn'], (float) ($row['low_ssn'] ?? 0), (float) ($row['high_ssn'] ?? 0)];
            }
        }

        Console::success(sprintf('SolarCyclePlugin: %s — %.0f plam, prognoza do %s', $last, $observed[$last][0], array_key_last($predicted) ?? '—'));
        return ['observed' => $observed, 'predicted' => $predicted];
    }

    /** Pola dla plugins/solarCycle.json: liczba plam, opis cyklu i słupki (obserwacje + prognoza). */
    public function present(array $data): array
    {
        $observed = $data['observed'];
        $last     = array_key_last($observed);
        $value    = $observed[$last][0];

        // Szczyty cykli z wygładzonej liczby plam
        $peaks = [];
        foreach (self::CYCLES as $n => [$from, $to]) {
            foreach ($observed as $month => [, $smooth]) {
                if ($month >= $from && $month <= $to && $smooth !== null && $smooth > ($peaks[$n][1] ?? -1)) {
                    $peaks[$n] = [$month, $smooth];
                }
            }
        }

        $bars = [];
        foreach ($observed as $month => [$ssn]) {
            $bars[] = $this->bar($month, $ssn, $this->heat($ssn));
        }
        foreach ($data['predicted'] as $month => [$ssn]) {
            $bars[] = $this->bar($month, $ssn, '#3B4A6B');
        }

        $minimum = null;
        foreach ($data['predicted'] as $month => [$ssn]) {
            if ($minimum === null || $ssn < $data['predicted'][$minimum][0]) {
                $minimum = $month;
            }
        }

        [$p25, $v25] = $peaks[25] ?? [null, null];
        $sinceMax = $p25 ? $this->monthsBetween($p25, $last) : null;

        return [
            'valueText'  => sprintf('%s %s', number_format($value, 0, ',', ' '), $this->plural((int) round($value), 'plama', 'plamy', 'plam')),
            'monthText'  => $this->monthName($last),
            'cycleText'  => $p25 === null ? '' : sprintf('Szczyt cyklu: %s %s, średnio %s plam%s',
                self::MONTHS[(int) substr($p25, 5) - 1], substr($p25, 0, 4), number_format($v25, 0, ',', ' '),
                $sinceMax !== null && $sinceMax > 0 ? ' — ' . $this->ago($sinceMax) . ' temu' : ''),
            'trendText'  => trim((isset($peaks[24]) ? sprintf('Poprzedni cykl: szczyt %s plam', number_format($peaks[24][1], 0, ',', ' ')) : '')
                . ($minimum ? sprintf(' · NOAA: minimum ok. %s, potem cykl 26', substr($minimum, 0, 4)) : ''), ' ·'),
            'bars'       => $bars,
        ] + $data;
    }

    /** Słupek miesiąca; podpis pod osią w styczniu co 2 lata. */
    private function bar(string $month, float $ssn, string $color): array
    {
        $year = (int) substr($month, 0, 4);
        return [
            'label' => (string) $year,
            'value' => round($ssn, 1),
            'color' => $color,
            'tick'  => substr($month, 5) === '01' && $year % 2 === 0,
        ];
    }

    /** Kolor wg liczby plam: spokojne Słońce przygaszone, aktywne pomarańczowe. */
    private function heat(float $ssn): string
    {
        return match (true) {
            $ssn >= 150 => '#F97316',
            $ssn >= 100 => '#FB923C',
            $ssn >= 50  => '#FBBF24',
            $ssn >= 20  => '#CA8A04',
            default     => '#854D0E',
        };
    }

    private function monthsBetween(string $from, string $to): int
    {
        return ((int) substr($to, 0, 4) - (int) substr($from, 0, 4)) * 12 + (int) substr($to, 5) - (int) substr($from, 5);
    }

    /** 23 miesiące → „rok i 11 miesięcy” */
    private function ago(int $months): string
    {
        $y = intdiv($months, 12);
        $m = $months % 12;
        $years = match (true) {
            $y === 0 => '',
            $y === 1 => 'rok',
            default  => $y . ' ' . $this->plural($y, 'rok', 'lata', 'lat'),
        };
        $rest = $m ? $m . ' ' . $this->plural($m, 'miesiąc', 'miesiące', 'miesięcy') : '';
        return trim($years . ($years && $rest ? ' i ' : '') . $rest);
    }

    private function monthName(string $month): string
    {
        return self::MONTHS[(int) substr($month, 5) - 1] . ' ' . substr($month, 0, 4);
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }
        $d = $n % 10;
        $t = $n % 100;
        return $d >= 2 && $d <= 4 && ($t < 12 || $t > 14) ? $few : $many;
    }
}
