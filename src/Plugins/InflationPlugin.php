<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Inflacja w Polsce: zharmonizowany wskaźnik cen HICP r/r z Eurostatu (GUS liczy go dla Eurostatu; różni się od
 * krajowego CPI o kilka dziesiątych). Od 2026 dane są w klasyfikacji ECOICOP 2 (zbiór prc_hicp_minr), wcześniejsze
 * miesiące do wykresu — ze starego prc_hicp_manr. Do tego kategorie, które najmocniej drożeją, i porównanie
 * z UE i sąsiadami. Wygląd — ColumnChart i dwie tabele w plugins/inflation.json.
 *
 * Krajowy CPI z API GUS (SDP) ma od 2026 nową, nieopisaną publicznie zmienną — stąd Eurostat.
 */
class InflationPlugin extends AbstractPlugin
{
    private const API = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/';

    /** Kategorie ECOICOP 2 → polska nazwa */
    private const CATEGORIES = [
        'CP01'  => 'Żywność',
        'CP02'  => 'Alkohol i papierosy',
        'CP045' => 'Prąd, gaz i opał',
        'CP04'  => 'Mieszkanie i media',
        'CP06'  => 'Zdrowie',
        'CP07'  => 'Transport',
        'CP11'  => 'Restauracje i hotele',
    ];

    private const COUNTRIES = [
        'PL'        => 'Polska',
        'EU27_2020' => 'Średnio w UE',
        'DE'        => 'Niemcy',
        'CZ'        => 'Czechy',
        'SK'        => 'Słowacja',
        'LT'        => 'Litwa',
        'HU'        => 'Węgry',
    ];

    /** Cel inflacyjny NBP: 2,5% ± 1 pkt proc. */
    private const TARGET = [1.5, 3.5];

    private const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("InflationPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Inflacja w Polsce'; }

    public function getCredit(): string { return 'Eurostat'; }

    public function fetchData(): array
    {
        Console::plugin("InflationPlugin: pobieram HICP z Eurostatu...");

        // Seria Polski: stara klasyfikacja do grudnia 2025 + nowa od stycznia 2026
        $old = $this->query('prc_hicp_manr', ['geo' => ['PL'], 'coicop' => ['CP00'], 'sinceTimePeriod' => [date('Y-m', strtotime('-36 months'))]]);
        $new = $this->query('prc_hicp_minr', [
            'unit'            => ['RCH_A'],
            'geo'             => array_keys(self::COUNTRIES),
            'coicop18'        => ['TOTAL', ...array_keys(self::CATEGORIES)],
            'sinceTimePeriod' => ['2026-01'],
        ]);

        $series = [];
        foreach ($old as $key => $value) {
            [, , , , $month] = explode('|', $key);
            $series[$month] = $value;
        }
        $latest = [];   // [coicop][geo] → [miesiąc, wartość] — ostatni dostępny miesiąc osobno dla każdego
        foreach ($new as $key => $value) {
            [, , $coicop, $geo, $month] = explode('|', $key);
            if ($coicop === 'TOTAL' && $geo === 'PL') {
                $series[$month] = $value;
            }
            if ($month >= ($latest[$coicop][$geo][0] ?? '')) {
                $latest[$coicop][$geo] = [$month, $value];
            }
        }
        ksort($series);
        if (!$series || !isset($latest['TOTAL']['PL'])) {
            throw new RuntimeException('Eurostat: brak HICP dla Polski');
        }

        $month = array_key_last($series);
        Console::success(sprintf('InflationPlugin: %s — %.1f%% r/r', $month, $series[$month]));
        return ['month' => $month, 'series' => $series, 'latest' => $latest];
    }

    /**
     * Zapytanie JSON-stat: wartości jako „wymiar1|wymiar2|…” (kody kategorii w kolejności wymiarów) → liczba.
     * @param array<string, list<string>> $filters
     * @return array<string, float>
     */
    private function query(string $dataset, array $filters): array
    {
        $parts = [];
        foreach ($filters as $name => $values) {
            foreach ($values as $v) {
                $parts[] = rawurlencode($name) . '=' . rawurlencode($v);
            }
        }
        $json = $this->http->get(self::API . $dataset . '?' . implode('&', $parts));
        if (!isset($json['value'], $json['id'], $json['size'], $json['dimension'])) {
            throw new RuntimeException("Eurostat ({$dataset}): nieoczekiwana odpowiedź — " . json_encode($json['error'] ?? null));
        }

        // Kody każdego wymiaru w kolejności indeksów
        $codes = [];
        foreach ($json['id'] as $dim) {
            $index = $json['dimension'][$dim]['category']['index'];
            asort($index);
            $codes[] = array_keys($index);
        }
        $out = [];
        foreach ($json['value'] as $flat => $value) {
            $key = [];
            for ($d = count($json['size']) - 1, $rest = (int) $flat; $d >= 0; $d--) {
                $key[$d] = $codes[$d][$rest % $json['size'][$d]];
                $rest    = intdiv($rest, $json['size'][$d]);
            }
            ksort($key);
            $out[implode('|', $key)] = (float) $value;
        }
        return $out;
    }

    /** Pola dla plugins/inflation.json: wartość, opis względem celu NBP, słupki, kategorie i kraje. */
    public function present(array $data): array
    {
        $series = $data['series'];
        $month  = $data['month'];
        $value  = $series[$month];
        $months = array_keys($series);
        $prev   = $series[$months[count($months) - 2]] ?? null;

        $bars = [];
        foreach (array_slice($series, -24, null, true) as $m => $v) {
            $bars[] = ['label' => $this->short($m), 'value' => $v, 'color' => $this->color($v), 'tick' => in_array(substr($m, 5), ['01', '07'], true)];
        }

        $categories = [];
        foreach (self::CATEGORIES as $code => $name) {
            if (isset($data['latest'][$code]['PL'])) {
                $categories[$name] = $data['latest'][$code]['PL'][1];
            }
        }
        arsort($categories);
        $catRows = array_map(fn($name, $v) => ['cells' => [$name, $this->pct($v)], 'color' => $this->color($v)], array_keys($categories), $categories);

        $countries = [];
        foreach (self::COUNTRIES as $geo => $name) {
            if (isset($data['latest']['TOTAL'][$geo])) {
                $countries[] = ['cells' => [$name, $this->pct($data['latest']['TOTAL'][$geo][1])],
                    'color' => $geo === 'PL' ? '#FBBF24' : '#CBD5E1'];
            }
        }

        [$low, $high] = self::TARGET;
        return [
            'monthText'  => self::MONTHS[(int) substr($month, 5) - 1] . ' ' . substr($month, 0, 4),
            'valueText'  => $this->pct($value, false) . ' r/r',
            'valueColor' => $this->color($value),
            'targetText' => ($value > $high ? 'Powyżej celu NBP (2,5% ± 1)' : ($value < $low ? 'Poniżej celu NBP (2,5% ± 1)' : 'W celu NBP (2,5% ± 1)'))
                . ($prev !== null ? sprintf(' · miesiąc wcześniej %s', $this->pct($prev, false)) : ''),
            'bars'       => $bars,
            'catRows'    => $catRows,
            'countryRows' => $countries,
        ] + $data;
    }

    private function color(float $v): string
    {
        return match (true) {
            $v > 6               => '#F87171',
            $v > self::TARGET[1] => '#FB923C',
            $v >= self::TARGET[0] => '#4ADE80',
            default              => '#7DD3FC',
        };
    }

    /** 3.94 → „+3,9%” (albo „3,9%” bez znaku) */
    private function pct(float $v, bool $sign = true): string
    {
        $s = number_format(abs($v), 1, ',', ' ') . '%';
        return $v < 0 ? '−' . $s : ($sign ? '+' . $s : $s);
    }

    /** 2026-09 → „09.26” */
    private function short(string $month): string
    {
        return substr($month, 5) . '.' . substr($month, 2, 2);
    }
}
