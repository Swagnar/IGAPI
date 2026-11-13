<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Indeks Big Maca (The Economist, dane z GitHuba): ile kosztuje Big Mac w Polsce, ile to w dolarach
 * i czy złoty jest według indeksu zaniżony albo zawyżony wobec dolara i euro — plus porównanie cen w kilku krajach.
 * Economist publikuje nowe dane dwa razy w roku (styczeń, lipiec), więc slajd zmienia się rzadko.
 */
class BigMacPlugin extends AbstractPlugin
{
    private const CSV_URL = 'https://raw.githubusercontent.com/TheEconomist/big-mac-data/master/output-data/big-mac-full-index.csv';

    /** Kraje na wykresie: kod ISO3 z pliku → [nazwa, kod flagi na flagcdn.com] */
    private const COUNTRIES = [
        'CHE' => ['Szwajcaria', 'ch'], 'NOR' => ['Norwegia', 'no'], 'GBR' => ['Wielka Brytania', 'gb'],
        'EUZ' => ['Strefa euro', 'eu'], 'USA' => ['USA', 'us'], 'POL' => ['Polska', 'pl'],
        'CZE' => ['Czechy', 'cz'], 'HUN' => ['Węgry', 'hu'], 'CHN' => ['Chiny', 'cn'], 'JPN' => ['Japonia', 'jp'],
    ];

    /** Poniżej tego odchylenia (±3%) złoty jest „wyceniony uczciwie” */
    private const FAIR = 0.03;

    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("BigMacPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Indeks Big Maca'; }

    public function getCredit(): string { return 'The Economist'; }

    public function fetchData(): array
    {
        Console::plugin("BigMacPlugin: pobieram indeks Big Maca z GitHuba The Economist...");
        $lines  = preg_split('/\r?\n/', trim($this->http->getText(self::CSV_URL)));
        $header = str_getcsv(array_shift($lines));

        // Wiersze z ostatniego wydania + historia Polski (do porównania z rokiem wcześniej)
        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            if (count($cols) === count($header)) {
                $rows[] = array_combine($header, $cols);
            }
        }
        if (!$rows) {
            throw new RuntimeException('Indeks Big Maca: pusty plik CSV');
        }
        $date   = max(array_column($rows, 'date'));
        $latest = array_filter($rows, fn($r) => $r['date'] === $date && isset(self::COUNTRIES[$r['iso_a3']]));
        $poland = array_values(array_filter($rows, fn($r) => $r['iso_a3'] === 'POL'));
        if (!array_filter($latest, fn($r) => $r['iso_a3'] === 'POL')) {
            throw new RuntimeException("Indeks Big Maca: brak Polski w wydaniu {$date}");
        }

        $countries = array_values(array_map(fn($r) => [
            'iso'         => $r['iso_a3'],
            'localPrice'  => (float) $r['local_price'],
            'dollarPrice' => round((float) $r['dollar_price'], 3),
            'usdRaw'      => (float) $r['USD_raw'],
            'eurRaw'      => (float) $r['EUR_raw'],
            'rate'        => (float) $r['dollar_ex'],
        ], $latest));

        Console::success("BigMacPlugin: wydanie {$date}, " . count($countries) . ' krajów');
        return [
            'date'      => $date,
            'countries' => $countries,
            'history'   => array_map(fn($r) => ['date' => $r['date'], 'price' => (float) $r['local_price']], $poland),
        ];
    }

    /** Pola dla plugins/bigMac.json: cena w Polsce, porównanie z USA, wycena złotego i wiersze wykresu cen w $. */
    public function present(array $data): array
    {
        $byIso  = array_column($data['countries'], null, 'iso');
        $pl     = $byIso['POL'];
        $month  = (int) substr($data['date'], 5, 2);
        $year   = substr($data['date'], 0, 4);
        $zl     = fn(float $v) => number_format($v, 2, ',', ' ') . ' zł';
        $usd    = fn(float $v) => number_format($v, 2, ',', ' ') . ' $';
        $pct    = fn(float $v) => (int) round(abs($v) * 100) . '%';

        $vsUsa = abs($pl['usdRaw']) < self::FAIR ? 'tyle samo co w USA'
            : ($pl['usdRaw'] < 0 ? 'o ' . $pct($pl['usdRaw']) . ' taniej niż w USA' : 'o ' . $pct($pl['usdRaw']) . ' drożej niż w USA');
        $value = fn(float $raw) => abs($raw) < self::FAIR ? 'wyceniony uczciwie'
            : ($raw < 0 ? "zaniżony o {$pct($raw)}" : "zawyżony o {$pct($raw)}");

        // Rok wcześniej = to samo wydanie (miesiąc) rok temu
        $before = array_values(array_filter($data['history'], fn($h) => $h['date'] === ($year - 1) . substr($data['date'], 4)))[0] ?? null;
        $change = $before ? sprintf('Rok temu %s (%s%d%%)', $zl($before['price']),
            $pl['localPrice'] >= $before['price'] ? '+' : '−', (int) round(abs($pl['localPrice'] / $before['price'] - 1) * 100)) : null;

        $countries = $data['countries'];
        usort($countries, fn($a, $b) => $b['dollarPrice'] <=> $a['dollarPrice']);

        return [
            'edition'    => self::MONTHS[$month] . ' ' . $year,
            'priceText'  => $zl($pl['localPrice']),
            'usdLine'    => 'po kursie ' . $usd($pl['dollarPrice']) . " — {$vsUsa}",
            'valueLine'  => 'Złoty wobec dolara ' . $value($pl['usdRaw']) . ', wobec euro ' . $value($pl['eurRaw']),
            'yearLine'   => $change,
            'rows'       => array_map(fn($c) => [
                'label'      => self::COUNTRIES[$c['iso']][0],
                'value'      => $c['dollarPrice'],
                'valueLabel' => $usd($c['dollarPrice']),
                'icon'       => 'https://flagcdn.com/32x24/' . self::COUNTRIES[$c['iso']][1] . '.png',
                'color'      => $c['iso'] === 'POL' ? '#FBBF24' : null,
            ], $countries),
        ] + $data;
    }
}
