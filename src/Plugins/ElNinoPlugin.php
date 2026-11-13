<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * El Niño: tygodniowa anomalia temperatury powierzchni oceanu w regionie Niño 3.4 (środkowy Pacyfik, NOAA CPC,
 * norma 1991–2020) — dziś, rok temu i od kiedy nie było tak ciepło/zimno. Wygląd — plugins/elNino.json (1/4).
 */
class ElNinoPlugin extends AbstractPlugin
{
    private const URL = 'https://www.cpc.ncep.noaa.gov/data/indices/wksst9120.for';

    /** Wykres: ostatnie 2 lata tygodni */
    private const WEEKS = 104;

    private const MONTHS = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

    private const MONTHS_GEN = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("ElNinoPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'El Niño'; }

    public function getCredit(): string { return 'NOAA CPC'; }

    public function fetchData(): array
    {
        Console::plugin("ElNinoPlugin: pobieram anomalie Niño 3.4 z NOAA CPC...");

        // Wiersz: " 04NOV2026     27.0 5.6     29.5 4.4     30.1 3.3     30.1 1.5" — pary SST i anomalia
        // dla Niño 1+2, Niño 3, Niño 3.4, Niño 4; nas interesuje anomalia Niño 3.4 (6. liczba).
        // Kolumny mają stałą szerokość, więc ujemna anomalia bywa sklejona z SST: "25.6-0.3"
        $weeks = [];
        foreach (preg_split('/\R/', $this->http->getText(self::URL)) as $line) {
            if (preg_match('/^\s*(\d{2})([A-Z]{3})(\d{4})(.*)$/', $line, $m)
                && preg_match_all('/-?\d+\.\d/', $m[4], $n) === 8) {
                $date = \DateTimeImmutable::createFromFormat('!dMY', $m[1] . ucfirst(strtolower($m[2])) . $m[3]);
                $nums = $n[0];
                if ($date !== false) {
                    $weeks[$date->format('Y-m-d')] = (float) $nums[5];
                }
            }
        }
        if (count($weeks) < self::WEEKS) {
            throw new RuntimeException('NOAA CPC: za mało tygodni anomalii Niño 3.4 (' . count($weeks) . ')');
        }
        ksort($weeks);

        Console::success(sprintf('ElNinoPlugin: tydzień od %s — Niño 3.4 %+.1f °C', array_key_last($weeks), end($weeks)));
        return ['weeks' => $weeks];
    }

    /** Pola dla plugins/elNino.json: anomalia, faza, porównania i słupki z 2 lat. */
    public function present(array $data): array
    {
        $weeks = $data['weeks'];
        $last  = array_key_last($weeks);
        $value = $weeks[$last];
        [$phase, $color] = $this->phase($value);

        // Rok temu — najbliższy tydzień 52 tygodnie wcześniej
        $dates   = array_keys($weeks);
        $yearAgo = $weeks[$dates[count($dates) - 53]] ?? null;

        // Od kiedy nie było tak daleko od normy w tę samą stronę (pomijając ostatni rok — to ten sam epizod)
        $since = null;
        if (abs($value) >= 0.5) {
            $cutoff = (new \DateTimeImmutable($last))->modify('-1 year')->format('Y-m-d');
            foreach (array_reverse($weeks, true) as $date => $v) {
                if ($date < $cutoff && ($value > 0 ? $v >= $value : $v <= $value)) {
                    $since = (int) substr($date, 0, 4);
                    break;
                }
            }
        }

        $bars = [];
        foreach (array_slice($weeks, -self::WEEKS, null, true) as $date => $v) {
            $month  = (int) substr($date, 5, 2);
            $bars[] = [
                'label' => self::MONTHS[$month - 1] . ' ' . substr($date, 2, 2),
                'value' => $v,
                'color' => $this->phase($v)[1],
                'tick'  => (int) substr($date, 8, 2) <= 7 && $month % 6 === 1,
            ];
        }

        return [
            'valueText'   => $this->signed($value) . ' °C',
            'valueColor'  => $color,
            'phaseText'   => $phase,
            'compareText' => trim(($yearAgo !== null ? 'Rok temu ' . $this->signed($yearAgo) . ' °C' : '')
                . ($since !== null ? ' · najwięcej od ' . $since : (abs($value) >= 0.5 ? ' · rekord od ' . substr(array_key_first($weeks), 0, 4) : '')), ' ·'),
            'dateText'    => sprintf('tydzień od %d %s', (int) substr($last, 8, 2), self::MONTHS_GEN[(int) substr($last, 5, 2) - 1]),
            'bars'        => $bars,
        ] + $data;
    }

    /** Faza wg progów NOAA (anomalia Niño 3.4) i kolor słupka. */
    private function phase(float $v): array
    {
        return match (true) {
            $v >= 2.0  => ['Bardzo silny El Niño', '#DC2626'],
            $v >= 1.5  => ['Silny El Niño', '#EF4444'],
            $v >= 1.0  => ['Umiarkowany El Niño', '#F87171'],
            $v >= 0.5  => ['Słaby El Niño', '#FCA5A5'],
            $v > -0.5  => ['Neutralnie — ani El Niño, ani La Niña', '#64748B'],
            $v > -1.0  => ['Słaba La Niña', '#93C5FD'],
            $v > -1.5  => ['Umiarkowana La Niña', '#60A5FA'],
            default    => ['Silna La Niña', '#3B82F6'],
        };
    }

    private function signed(float $v): string
    {
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v), 1, ',', ' ');
    }
}
