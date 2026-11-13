<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;
use ZipArchive;

/**
 * Ceny mieszkań: transakcyjne ceny m² na rynku wtórnym w 17 miastach wojewódzkich (baza cen mieszkań NBP, kwartalnie)
 * — Warszawa, zmiana w rok, najtańsze miasto i mapa Polski z ceną jako ciepłem. Wygląd — plugins/housing.json (1/4).
 */
class HousingPlugin extends AbstractPlugin
{
    private const URL = 'https://static.nbp.pl/dane/rynek-nieruchomosci/ceny_mieszkan.xlsx';

    /** Arkusz „Rynek wtórny” — ceny transakcyjne w kolumnach Y–AO, kwartał w X */
    private const SHEET = 'xl/worksheets/sheet3.xml';
    private const QUARTER_COL = 'X';
    private const CITY_COLS = [
        'Y' => 'Białystok', 'Z' => 'Bydgoszcz', 'AA' => 'Gdańsk', 'AB' => 'Gdynia', 'AC' => 'Katowice', 'AD' => 'Kielce',
        'AE' => 'Kraków', 'AF' => 'Lublin', 'AG' => 'Łódź', 'AH' => 'Olsztyn', 'AI' => 'Opole', 'AJ' => 'Poznań',
        'AK' => 'Rzeszów', 'AL' => 'Szczecin', 'AM' => 'Warszawa', 'AN' => 'Wrocław', 'AO' => 'Zielona Góra',
    ];

    private const COORDS = [
        'Białystok' => [23.16, 53.13], 'Bydgoszcz' => [18.01, 53.12], 'Gdańsk' => [18.65, 54.35], 'Gdynia' => [18.53, 54.52],
        'Katowice' => [19.02, 50.26], 'Kielce' => [20.63, 50.87], 'Kraków' => [19.94, 50.06], 'Lublin' => [22.57, 51.25],
        'Łódź' => [19.46, 51.77], 'Olsztyn' => [20.49, 53.78], 'Opole' => [17.93, 50.67], 'Poznań' => [16.93, 52.41],
        'Rzeszów' => [22.00, 50.04], 'Szczecin' => [14.55, 53.43], 'Warszawa' => [21.01, 52.23], 'Wrocław' => [17.04, 51.11],
        'Zielona Góra' => [15.51, 51.94],
    ];

    /** Miasta podpisane na małej mapie — reszta tylko barwi ciepło */
    private const LABELLED = ['Warszawa', 'Gdańsk', 'Kraków'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("HousingPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Ceny mieszkań'; }

    public function getCredit(): string { return 'NBP'; }

    public function fetchData(): array
    {
        Console::plugin("HousingPlugin: pobieram bazę cen mieszkań NBP...");

        $file = tempnam(sys_get_temp_dir(), 'nbp');
        file_put_contents($file, $this->http->getText(self::URL));
        try {
            $rows = $this->readRows($file);
        } finally {
            @unlink($file);
        }

        // Kwartał „II 2026” → miasto → cena; tylko wiersze z kompletem miast
        $quarters = [];
        foreach ($rows as $row) {
            if (!preg_match('/^(I{1,3}|IV) (\d{4})$/', $row[self::QUARTER_COL] ?? '', $m)) {
                continue;
            }
            $prices = [];
            foreach (self::CITY_COLS as $col => $city) {
                if (is_numeric($row[$col] ?? null)) {
                    $prices[$city] = round((float) $row[$col]);
                }
            }
            if (count($prices) === count(self::CITY_COLS)) {
                $quarters[$m[2] . '-' . ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4][$m[1]]] = $prices;
            }
        }
        if (count($quarters) < 5) {
            throw new RuntimeException('NBP: za mało kwartałów z cenami mieszkań (' . count($quarters) . ')');
        }
        ksort($quarters);

        $last = array_key_last($quarters);
        Console::success(sprintf('HousingPlugin: %s — Warszawa %.0f zł/m²', $last, $quarters[$last]['Warszawa']));
        return ['quarters' => array_slice($quarters, -5, null, true)];
    }

    /** Pola dla plugins/housing.json: Warszawa, zmiana w rok, najtańsze miasto, punkty mapy. */
    public function present(array $data): array
    {
        $quarters = $data['quarters'];
        $last     = array_key_last($quarters);
        $now      = $quarters[$last];
        $yearAgo  = $quarters[((int) substr($last, 0, 4) - 1) . substr($last, 4)] ?? null;

        $change = $yearAgo ? ($now['Warszawa'] / $yearAgo['Warszawa'] - 1) * 100 : null;
        asort($now);
        $cheapest = array_key_first($now);

        $points = [];
        foreach ($now as $city => $price) {
            [$lon, $lat] = self::COORDS[$city];
            $label    = in_array($city, self::LABELLED, true) || $city === $cheapest;
            $points[] = [
                'lon'   => $lon,
                'lat'   => $lat,
                'value' => $price / 1000,
                'label' => $label ? $city : '',
                'sub'   => $label ? $this->thousands($price) . ' zł' : null,
                'priority' => $city === 'Warszawa',
            ];
        }

        [$year, $q] = explode('-', $last);
        return [
            'dateText'     => ['I', 'II', 'III', 'IV'][(int) $q - 1] . ' kwartał ' . $year,
            'warsawText'   => $this->thousands($now['Warszawa']) . ' zł/m²',
            'changeText'   => $change === null ? '' : sprintf('%s%s%% w rok', $change >= 0 ? '+' : '−', number_format(abs($change), 1, ',', ' ')),
            'changeColor'  => $change !== null && $change < 0 ? '#4ADE80' : '#F87171',
            'cheapestText' => sprintf('Najtaniej: %s, %s zł/m²', $cheapest, $this->thousands($now[$cheapest])),
            'points'       => $points,
            'palette'      => [[8, [96, 165, 250], 70], [11, [250, 204, 21], 65], [14, [249, 115, 22], 60], [17, [239, 68, 68], 55]],
        ] + $data;
    }

    /** Wiersze arkusza jako [kolumna => wartość] — tekst z sharedStrings podstawiony. */
    private function readRows(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException('NBP: nie da się otworzyć XLSX z cenami mieszkań');
        }
        $strings = [];
        $shared  = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared !== false) {
            foreach (simplexml_load_string($shared)->si as $si) {
                $strings[] = trim(strip_tags($si->asXML()));
            }
        }
        $sheet = $zip->getFromName(self::SHEET);
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('NBP: brak arkusza „Rynek wtórny” w XLSX');
        }

        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $value = (string) $c->v;
                if ((string) $c['t'] === 's') {
                    $value = $strings[(int) $value] ?? '';
                }
                $cells[preg_replace('/\d+/', '', (string) $c['r'])] = $value;
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    private function thousands(float $v): string
    {
        return number_format($v, 0, ',', ' ');
    }
}
