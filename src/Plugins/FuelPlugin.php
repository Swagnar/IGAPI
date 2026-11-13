<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Ceny paliw: średnie ceny detaliczne z podatkami z cotygodniowego Biuletynu naftowego Komisji Europejskiej
 * (Weekly Oil Bulletin, dane od państw — dla Polski z Ministerstwa Klimatu). Plik historii (XLSX) ma wszystkie
 * kraje UE w EUR za 1000 l i kurs waluty krajowej; przeliczamy na zł/l kursem z biuletynu. Pokazujemy Pb95, ON
 * i LPG w Polsce (tydzień i rok wcześniej), wykres benzyny z roku i mapę cen Pb95 w Europie.
 * Wygląd — Table, LineChart i HeatMap w plugins/fuel.json.
 */
class FuelPlugin extends AbstractPlugin
{
    private const PAGE  = 'https://energy.ec.europa.eu/data-and-analysis/weekly-oil-bulletin_en';
    private const HOST  = 'https://energy.ec.europa.eu';
    private const WEEKS = 53;

    /** Paliwo → [przyrostek kolumny w arkuszu, nazwa] */
    private const FUELS = [
        'pb95'   => ['price_with_tax_euro95', 'Benzyna 95'],
        'diesel' => ['price_with_tax_diesel', 'Olej napędowy'],
        'lpg'    => ['price_with_tax_LPG', 'LPG'],
    ];

    /** Kraj → [nazwa, lon, lat] — punkt na mapie */
    private const COUNTRIES = [
        'PL' => ['Polska', 19.4, 52.0], 'DE' => ['Niemcy', 10.4, 51.2], 'CZ' => ['Czechy', 15.4, 49.8],
        'SK' => ['Słowacja', 19.5, 48.7], 'LT' => ['Litwa', 23.9, 55.3], 'AT' => ['Austria', 14.5, 47.6],
        'HU' => ['Węgry', 19.4, 47.2], 'FR' => ['Francja', 2.5, 46.6], 'IT' => ['Włochy', 12.5, 42.8],
        'ES' => ['Hiszpania', -3.7, 40.2], 'PT' => ['Portugalia', -8.2, 39.7], 'NL' => ['Holandia', 5.5, 52.2],
        'BE' => ['Belgia', 4.6, 50.6], 'LU' => ['Luksemburg', 6.1, 49.8], 'DK' => ['Dania', 9.3, 56.1],
        'SE' => ['Szwecja', 15.5, 60.5], 'FI' => ['Finlandia', 26.0, 62.5], 'EE' => ['Estonia', 25.5, 58.7],
        'LV' => ['Łotwa', 24.9, 56.9], 'IE' => ['Irlandia', -8.0, 53.2], 'SI' => ['Słowenia', 14.8, 46.1],
        'HR' => ['Chorwacja', 16.0, 45.4], 'RO' => ['Rumunia', 25.0, 45.9], 'BG' => ['Bułgaria', 25.2, 42.7],
        'GR' => ['Grecja', 22.5, 39.4], 'CY' => ['Cypr', 33.2, 35.0], 'MT' => ['Malta', 14.4, 35.9],
    ];

    private const ALWAYS_LABELLED = ['PL', 'DE', 'CZ', 'LT', 'FR', 'IT', 'ES'];

    private const VIEW = [-11.0, 34.0, 35.0, 71.0];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("FuelPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Ceny paliw'; }

    public function getCredit(): string { return 'Komisja Europejska'; }   // Weekly Oil Bulletin

    public function fetchData(): array
    {
        Console::plugin("FuelPlugin: pobieram Biuletyn naftowy UE...");

        // Link do pliku historii zmienia się przy aktualizacjach — szukamy go na stronie biuletynu
        $page = $this->http->getText(self::PAGE);
        if (!preg_match('/href="([^"]*Prices_History[^"]*\.xlsx)"/i', $page, $m)) {
            throw new RuntimeException('Biuletyn naftowy: brak linku do pliku historii cen na stronie');
        }
        $url  = html_entity_decode($m[1]);
        $xlsx = $this->http->getText(str_starts_with($url, 'http') ? $url : self::HOST . $url);

        $file = tempnam(sys_get_temp_dir(), 'oil');
        file_put_contents($file, $xlsx);
        try {
            $rows = $this->readRows($file);
        } finally {
            unlink($file);
        }

        // Wiersz 1: nagłówki „PL_price_with_tax_euro95”, „PL_exchange_rate” → kolumna
        $columns = array_flip(array_filter($rows[1] ?? [], 'is_string'));
        $weeks   = [];
        foreach ($rows as $n => $row) {
            if ($n < 4 || !is_numeric($row['A'] ?? null)) {
                continue;
            }
            $date   = gmdate('Y-m-d', ((int) $row['A'] - 25569) * 86400);   // data seryjna Excela
            $prices = [];
            foreach (array_keys(self::COUNTRIES) as $cc) {
                // Kraje poza strefą euro mają kolumnę kursu (EUR za 1 jednostkę waluty), strefa euro — nie
                foreach (self::FUELS as $fuel => [$suffix]) {
                    $col = $columns["{$cc}_{$suffix}"] ?? null;
                    if ($col !== null && is_numeric($row[$col] ?? null) && (float) $row[$col] > 0) {
                        $prices[$cc][$fuel] = round((float) $row[$col], 2);   // EUR / 1000 l
                    }
                }
            }
            $plRate = $columns['PL_exchange_rate'] ?? null;
            if (!isset($prices['PL']['pb95']) || $plRate === null || !is_numeric($row[$plRate] ?? null)) {
                continue;
            }
            $weeks[$date] = ['eurPln' => round(1 / (float) $row[$plRate], 4), 'prices' => $prices];
        }
        if (!$weeks) {
            throw new RuntimeException('Biuletyn naftowy: brak cen dla Polski w pliku historii');
        }
        krsort($weeks);
        $weeks = array_slice($weeks, 0, self::WEEKS, true);

        // Do cache: cały świeży tydzień (mapa), z reszty tylko Polska (wykres)
        $latest = array_key_first($weeks);
        $poland = [];
        foreach ($weeks as $date => $w) {
            $poland[$date] = array_map(fn($eur) => round($eur / 1000 * $w['eurPln'], 3), $w['prices']['PL']);
        }
        ksort($poland);

        Console::success(sprintf('FuelPlugin: %s — Pb95 %.2f zł, ON %.2f zł', $latest, $poland[$latest]['pb95'], $poland[$latest]['diesel'] ?? 0));
        return ['date' => $latest, 'eurPln' => $weeks[$latest]['eurPln'], 'europe' => $weeks[$latest]['prices'], 'poland' => $poland];
    }

    /**
     * Wiersze arkusza „Prices with taxes” (sheet1) — strumieniowo, bo XML ma kilka MB, a potrzebujemy tylko nagłówka
     * i ostatnich tygodni (na górze arkusza). Komórki: kolumna → liczba albo tekst (shared strings).
     * @return array<int, array<string, float|string>>
     */
    private function readRows(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException('Biuletyn naftowy: plik XLSX uszkodzony');
        }
        $strings = [];
        $shared  = simplexml_load_string((string) $zip->getFromName('xl/sharedStrings.xml'));
        foreach ($shared?->si ?? [] as $si) {
            $text = (string) $si->t;
            foreach ($si->r ?? [] as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }
        $zip->close();

        $reader = new XMLReader();
        if (!$reader->open('zip://' . $file . '#xl/worksheets/sheet1.xml')) {
            throw new RuntimeException('Biuletyn naftowy: brak arkusza cen w pliku');
        }
        $rows = [];
        $last = 3 + self::WEEKS + 5;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
                continue;
            }
            $n = (int) $reader->getAttribute('r');
            if ($n > $last) {
                break;
            }
            $row = simplexml_load_string($reader->readOuterXml());
            foreach ($row->c as $c) {
                $col   = preg_replace('/\d+/', '', (string) $c['r']);
                $value = (string) $c->v;
                $rows[$n][$col] = (string) $c['t'] === 's' ? ($strings[(int) $value] ?? '') : (float) $value;
            }
        }
        $reader->close();
        return $rows;
    }

    /** Pola dla plugins/fuel.json: ceny w Polsce, zmiany, wykres benzyny i mapa Europy. */
    public function present(array $data): array
    {
        $poland = $data['poland'];
        $dates  = array_keys($poland);
        $now    = $poland[$data['date']];
        $week   = $poland[$dates[count($dates) - 2]] ?? null;
        $year   = $poland[$dates[0]] ?? null;

        $rows = [];
        foreach (self::FUELS as $fuel => [, $name]) {
            if (!isset($now[$fuel])) {
                continue;
            }
            $dw     = $week[$fuel] ?? null;
            $rows[] = ['cells' => [$name, $this->zl($now[$fuel]), $dw !== null ? $this->grosze($now[$fuel] - $dw) : '—',
                isset($year[$fuel]) ? $this->grosze($now[$fuel] - $year[$fuel]) : '—'],
                'color' => $dw === null ? '#E2E8F0' : ($now[$fuel] > $dw + 0.005 ? '#FCA5A5' : ($now[$fuel] < $dw - 0.005 ? '#86EFAC' : '#E2E8F0'))];
        }

        // Mapa: Pb95 w zł/l w każdym kraju (kurs EUR/PLN z biuletynu)
        $pb95 = [];
        foreach ($data['europe'] as $cc => $p) {
            if (isset($p['pb95'], self::COUNTRIES[$cc])) {
                $pb95[$cc] = $p['pb95'] / 1000 * $data['eurPln'];
            }
        }
        asort($pb95);
        $rank = array_search('PL', array_keys($pb95), true);
        [$min, $max] = [min($pb95), max($pb95)];
        $points = [];
        foreach ($pb95 as $cc => $v) {
            [$name, $lon, $lat] = self::COUNTRIES[$cc];
            $points[] = ['lon' => $lon, 'lat' => $lat, 'value' => $v,
                'label' => ($cc === 'PL' ? 'Polska ' : $cc . ' ') . number_format($v, 2, ',', ''),
                'color' => $this->labelColor($v, $min, $max), 'priority' => in_array($cc, self::ALWAYS_LABELLED, true)];
        }
        $priciest = array_key_last($pb95);

        return [
            'dateText'  => date('d.m.Y', strtotime($data['date'])),
            'headline'  => 'Pb95 ' . $this->zl($now['pb95']) . (isset($now['diesel']) ? ' · ON ' . $this->zl($now['diesel']) : ''),
            'rankText'  => $rank === false ? '' : sprintf('%d. najtańsza benzyna z %d krajów UE · najdrożej: %s %s',
                $rank + 1, count($pb95), self::COUNTRIES[$priciest][0], $this->zl($pb95[$priciest])),
            'rows'      => $rows,
            'points'    => array_map(fn($d, $p) => ['x' => strtotime($d), 'y' => $p['pb95']], $dates, $poland),
            'map'       => [
                'points'  => $points,
                'palette' => $this->palette($min, $max),
                'area'    => 'europe:' . implode(',', array_keys($pb95)),
                'land'    => 'europe:!' . implode(',', array_keys($pb95)),
                'view'    => self::VIEW,
            ],
        ] + $data;
    }

    private function zl(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' zł';
    }

    /** 0,13 → „+13 gr”, −0,05 → „−5 gr” */
    private function grosze(float $diff): string
    {
        $gr = (int) round($diff * 100);
        return $gr === 0 ? '0 gr' : ($gr > 0 ? '+' : '−') . abs($gr) . ' gr';
    }

    /** Jak na mapie cen prądu: od niebieskiego (tanio) do czerwonego (drogo), rozciągnięte na dzisiejszy zakres */
    private function palette(float $min, float $max): array
    {
        $at = fn(float $t) => $min + ($max - $min) * $t;
        return [
            [$at(0.0),  [80, 120, 255], 55],
            [$at(0.25), [100, 180, 255], 60],
            [$at(0.5),  [100, 200, 100], 60],
            [$at(0.75), [255, 200, 0], 55],
            [$at(1.0),  [255, 80, 80], 50],
        ];
    }

    private function labelColor(float $value, float $min, float $max): string
    {
        $t = $max > $min ? ($value - $min) / ($max - $min) : 0.5;
        return match (true) {
            $t >= 0.8 => '#F87171',
            $t >= 0.6 => '#FBBF24',
            $t >= 0.4 => '#86EFAC',
            default   => '#7DD3FC',
        };
    }
}
