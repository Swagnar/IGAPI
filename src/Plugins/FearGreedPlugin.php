<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Indeks strachu i chciwości rynku kryptowalut (alternative.me): dzisiejsza wartość 0–100 na liczniku,
 * porównanie z wczoraj / tydzień / miesiąc / rok temu i słupki z ostatnich 90 dni w kolorach stref.
 */
class FearGreedPlugin extends AbstractPlugin
{
    private const API_URL = 'https://api.alternative.me/fng/?limit=400';

    /** Ile dni na wykresie */
    private const DAYS = 90;

    /** Strefy indeksu: [górna granica, nazwa po polsku, kolor] */
    private const ZONES = [
        [24, 'Skrajny strach', '#EF4444'],
        [44, 'Strach', '#F97316'],
        [55, 'Neutralnie', '#FBBF24'],
        [75, 'Chciwość', '#A3E635'],
        [100, 'Skrajna chciwość', '#22C55E'],
    ];

    private const MONTHS = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("FearGreedPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Strach i chciwość'; }

    public function getCredit(): string { return 'alternative.me'; }

    public function fetchData(): array
    {
        Console::plugin("FearGreedPlugin: pobieram indeks strachu i chciwości...");
        $rows = $this->http->get(self::API_URL)['data'] ?? [];
        if (count($rows) < 2) {
            throw new RuntimeException('alternative.me: brak danych indeksu');
        }

        // API zwraca od najnowszego — odwracamy do kolejności w czasie, dzień → wartość
        $days = [];
        foreach (array_reverse($rows) as $row) {
            $days[gmdate('Y-m-d', (int) $row['timestamp'])] = (int) $row['value'];
        }

        Console::success('FearGreedPlugin: dziś ' . end($days) . ' (' . array_key_last($days) . ')');
        return ['days' => $days];
    }

    /** Pola dla plugins/fearGreed.json: wartość, strefa, porównania i słupki. */
    public function present(array $data): array
    {
        $days  = $data['days'];
        $today = array_key_last($days);
        $value = $days[$today];
        $zone  = $this->zone($value);

        $compare = [];
        foreach (['wczoraj' => '-1 day', 'tydzień temu' => '-7 days', 'miesiąc temu' => '-1 month', 'rok temu' => '-1 year'] as $label => $shift) {
            $then = $days[date('Y-m-d', strtotime("{$today} {$shift}"))] ?? null;
            if ($then !== null) {
                $z = $this->zone($then);
                $compare[] = ['cells' => [ucfirst($label), (string) $then, $z[1]], 'color' => $z[2]];
            }
        }

        $bars = [];
        foreach (array_slice($days, -self::DAYS, null, true) as $date => $v) {
            $day    = (int) substr($date, 8, 2);
            $bars[] = [
                'label' => $day === 1 ? self::MONTHS[(int) substr($date, 5, 2) - 1] : date('d.m', strtotime($date)),
                'value' => $v,
                'color' => $this->zone($v)[2],
                'tick'  => $day === 1,
            ];
        }

        return [
            'value'     => $value,
            'zoneName'  => $zone[1],
            'zoneColor' => $zone[2],
            'dateText'  => date('d.m.Y', strtotime($today)),
            'zones'     => array_map(fn($z) => ['to' => $z[0], 'color' => $z[2]], self::ZONES),
            'compare'   => $compare,
            'bars'      => $bars,
            'chartNote' => 'Ostatnie ' . self::DAYS . ' dni · 0 = skrajny strach, 100 = skrajna chciwość',
        ] + $data;
    }

    /** @return array{int, string, string} */
    private function zone(int $value): array
    {
        foreach (self::ZONES as $zone) {
            if ($value <= $zone[0]) {
                return $zone;
            }
        }
        return self::ZONES[array_key_last(self::ZONES)];
    }
}
