<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Temperatura Ziemi z Copernicus Climate Pulse (ERA5): średnia globalna z ostatniego dnia, jej odchylenie
 * od normy 1991–2020 i średnie roczne odchylenia od 1940 — na słupkach w kolorach „warming stripes”.
 */
class ClimatePlugin extends AbstractPlugin
{
    private const CSV_URL = 'https://sites.ecmwf.int/data/climatepulse/data/series/era5_daily_series_2t_global.csv';

    /** O tyle okres 1991–2020 był cieplejszy od przedprzemysłowego 1850–1900 (wartość przyjęta przez C3S) */
    private const PREINDUSTRIAL_OFFSET = 0.88;

    /** Paleta słupków: [odchylenie °C, [r, g, b]] — od błękitu przez biel do czerwieni, jak „warming stripes” */
    private const PALETTE = [
        [-1.2, [8, 48, 107]],
        [-0.8, [33, 113, 181]],
        [-0.4, [107, 174, 214]],
        [-0.1, [222, 235, 247]],
        [0.1,  [254, 224, 210]],
        [0.4,  [252, 146, 114]],
        [0.8,  [222, 45, 38]],
        [1.2,  [165, 15, 21]],
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("ClimatePlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Temperatura Ziemi'; }

    public function getCredit(): string { return 'Copernicus'; }

    public function fetchData(): array
    {
        Console::plugin("ClimatePlugin: pobieram serię ERA5 z Climate Pulse...");
        $csv = $this->http->getText(self::CSV_URL);

        $sums   = [];
        $counts = [];
        $last   = null;
        foreach (preg_split('/\r?\n/', $csv) as $line) {
            // date,2t,clim_91-20,ano_91-20,status — nagłówek i komentarze (#) odpadają na dacie
            $cols = explode(',', $line);
            if (count($cols) < 4 || !preg_match('/^(\d{4})-\d{2}-\d{2}$/', $cols[0], $m) || !is_numeric($cols[3])) {
                continue;
            }
            $year           = (int) $m[1];
            $sums[$year]    = ($sums[$year] ?? 0) + (float) $cols[3];
            $counts[$year]  = ($counts[$year] ?? 0) + 1;
            $last           = ['date' => $cols[0], 'temp' => (float) $cols[1], 'normal' => (float) $cols[2], 'anomaly' => (float) $cols[3]];
        }
        if ($last === null) {
            throw new RuntimeException('Climate Pulse: w pliku CSV nie ma danych dziennych');
        }

        $years = [];
        foreach ($sums as $year => $sum) {
            $years[] = ['year' => $year, 'anomaly' => round($sum / $counts[$year], 3), 'days' => $counts[$year]];
        }

        Console::success("ClimatePlugin: {$last['date']} — {$last['temp']}°C, odchylenie {$last['anomaly']}°C");
        return ['last' => $last, 'years' => $years];
    }

    /**
     * Pola dla plugins/climate.json: temperatura z dnia, odchylenie od normy i od epoki przedprzemysłowej,
     * słupki średnich rocznych. Bieżący rok jest niepełny — dostaje gwiazdkę.
     */
    public function present(array $data): array
    {
        $last    = $data['last'];
        $years   = $data['years'];
        $current = end($years);
        $anomaly = $last['anomaly'];
        $sign    = fn(float $v) => ($v >= 0 ? '+' : '−') . number_format(abs($v), 2, ',', '');

        // Najcieplejszy pełny rok — do podpisu pod wykresem
        $full    = array_filter($years, fn($y) => $y['days'] >= 365);
        $warmest = $full ? array_reduce($full, fn($a, $y) => $a === null || $y['anomaly'] > $a['anomaly'] ? $y : $a) : null;

        return [
            'dateText'          => date('d.m', strtotime($last['date'])),
            'tempText'          => number_format($last['temp'], 1, ',', '') . '°C',
            'anomalyText'       => $sign($anomaly) . '°C względem normy z lat 1991–2020',
            'anomalyColor'      => $anomaly >= 0 ? '#F87171' : '#60A5FA',
            'preindustrialText' => 'ok. ' . $sign($anomaly + self::PREINDUSTRIAL_OFFSET) . '°C ponad epokę przedprzemysłową',
            'bars'              => array_map(fn($y) => [
                'label' => $y['days'] < 365 ? $y['year'] . '*' : (string) $y['year'],
                'value' => $y['anomaly'],
                'color' => $this->color($y['anomaly']),
                'tick'  => $y['year'] % 20 === 0,
            ], $years),
            'chartNote'         => sprintf('Średnie roczne%s · *%d do %s',
                $warmest ? ' · najcieplej: ' . $warmest['year'] : '', $current['year'], date('d.m', strtotime($last['date']))),
        ] + $data;
    }

    private function color(float $value): string
    {
        $palette = self::PALETTE;
        if ($value <= $palette[0][0]) {
            return $this->hex($palette[0][1]);
        }
        for ($i = 0; $i < count($palette) - 1; $i++) {
            [$v1, $c1] = $palette[$i];
            [$v2, $c2] = $palette[$i + 1];
            if ($value <= $v2) {
                $k = ($value - $v1) / ($v2 - $v1);
                return $this->hex(array_map(fn($a, $b) => (int) round($a + ($b - $a) * $k), $c1, $c2));
            }
        }
        return $this->hex($palette[count($palette) - 1][1]);
    }

    private function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', ...$rgb);
    }
}
