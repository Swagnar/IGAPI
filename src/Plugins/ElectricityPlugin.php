<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Hurtowe ceny prądu na dziś (rynek dnia następnego) w strefach cenowych Europy — mapa cieplna
 * w stylu mapy pogody, cena w Polsce w zł/kWh, najtańsze/najdroższe kraje i godziny w Polsce.
 *
 * Źródła: Nord Pool (22 strefy jednym zapytaniem — to samo API, z którego korzysta ich strona)
 * + Energy-Charts (Fraunhofer ISE) dla stref spoza Nord Pool. Energy-Charts ma ostry limit
 * (~3 zapytania na 30 s), więc te strefy pobieramy powoli — całość trwa ~1,5 min, raz dziennie.
 */
class ElectricityPlugin extends AbstractPlugin
{
    private const NORDPOOL_URL  = 'https://dataportal-api.nordpoolgroup.com/api/DayAheadPrices';
    private const ENERGY_URL    = 'https://api.energy-charts.info/price';
    private const NBP_EUR_URL   = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json';
    private const ENERGY_PAUSE  = 8; // s między zapytaniami do Energy-Charts
    private const TIMEZONE      = 'Europe/Warsaw';

    /** strefa → [źródło, kod w źródle, kraj (ISO), lon, lat] — punkt to przybliżony środek strefy */
    private const ZONES = [
        'PL'       => ['np', 'PL',       'PL', 19.4, 52.0],
        'DE-LU'    => ['np', 'GER',      'DE', 10.4, 51.2],
        'AT'       => ['np', 'AT',       'AT', 14.5, 47.6],
        'BE'       => ['np', 'BE',       'BE', 4.6, 50.6],
        'NL'       => ['np', 'NL',       'NL', 5.5, 52.2],
        'FR'       => ['np', 'FR',       'FR', 2.5, 46.6],
        'DK1'      => ['np', 'DK1',      'DK', 9.3, 56.1],
        'DK2'      => ['np', 'DK2',      'DK', 12.1, 55.5],
        'NO1'      => ['np', 'NO1',      'NO', 10.8, 60.2],
        'NO2'      => ['np', 'NO2',      'NO', 7.5, 58.8],
        'NO3'      => ['np', 'NO3',      'NO', 10.4, 63.4],
        'NO4'      => ['np', 'NO4',      'NO', 18.0, 68.5],
        'NO5'      => ['np', 'NO5',      'NO', 6.0, 60.7],
        'SE1'      => ['np', 'SE1',      'SE', 20.5, 66.8],
        'SE2'      => ['np', 'SE2',      'SE', 16.0, 63.0],
        'SE3'      => ['np', 'SE3',      'SE', 15.5, 59.3],
        'SE4'      => ['np', 'SE4',      'SE', 13.8, 56.2],
        'FI'       => ['np', 'FI',       'FI', 26.0, 62.5],
        'EE'       => ['np', 'EE',       'EE', 25.5, 58.7],
        'LV'       => ['np', 'LV',       'LV', 24.9, 56.9],
        'LT'       => ['np', 'LT',       'LT', 23.9, 55.3],
        'BG'       => ['np', 'BG',       'BG', 25.2, 42.7],
        'RO'       => ['np', 'TEL',      'RO', 25.0, 45.9],
        'ES'       => ['ec', 'ES',       'ES', -3.7, 40.2],
        'PT'       => ['ec', 'PT',       'PT', -8.2, 39.7],
        'IT-North' => ['ec', 'IT-North', 'IT', 10.0, 45.3],
        'IT-South' => ['ec', 'IT-South', 'IT', 15.8, 40.8],
        'CH'       => ['ec', 'CH',       'CH', 8.2, 46.8],
        'CZ'       => ['ec', 'CZ',       'CZ', 15.4, 49.8],
        'SK'       => ['ec', 'SK',       'SK', 19.5, 48.7],
        'HU'       => ['ec', 'HU',       'HU', 19.4, 47.2],
        'SI'       => ['ec', 'SI',       'SI', 14.8, 46.1],
        'HR'       => ['ec', 'HR',       'HR', 16.0, 45.4],
        'GR'       => ['ec', 'GR',       'GR', 22.5, 39.4],
        'RS'       => ['ec', 'RS',       'RS', 20.8, 44.0],
    ];

    /** Kraje z danymi — kolorowane na mapie; LU leży w strefie DE-LU */
    private const ZONE_COUNTRIES_EXTRA = ['LU'];

    private const COUNTRY_NAMES = [
        'PL' => 'Polska', 'DE' => 'Niemcy', 'AT' => 'Austria', 'BE' => 'Belgia', 'NL' => 'Holandia', 'FR' => 'Francja',
        'DK' => 'Dania', 'NO' => 'Norwegia', 'SE' => 'Szwecja', 'FI' => 'Finlandia', 'EE' => 'Estonia', 'LV' => 'Łotwa',
        'LT' => 'Litwa', 'BG' => 'Bułgaria', 'RO' => 'Rumunia', 'ES' => 'Hiszpania', 'PT' => 'Portugalia', 'IT' => 'Włochy',
        'CH' => 'Szwajcaria', 'CZ' => 'Czechy', 'SK' => 'Słowacja', 'HU' => 'Węgry', 'SI' => 'Słowenia', 'HR' => 'Chorwacja',
        'GR' => 'Grecja', 'RS' => 'Serbia',
    ];

    /** Etykiety tych krajów zawsze widoczne; pozostałe — jeśli zmieszczą się bez nachodzenia */
    private const ALWAYS_LABELLED = ['PL', 'DE', 'FR', 'ES', 'IT'];

    /** Widok mapy: lonMin, lonMax, latMin, latMax */
    private const VIEW = [-11.0, 34.0, 35.0, 71.0];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("ElectricityPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Ceny prądu w Europie'; }

    public function getCredit(): string { return 'Nord Pool / Energy-Charts'; }

    public function fetchData(): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        Console::plugin("ElectricityPlugin: pobieram ceny z Nord Pool na {$today}...");

        [$prices, $plHours] = $this->fetchNordPool($today);
        foreach (self::ZONES as $zone => [$source, $code]) {
            if ($source !== 'ec') {
                continue;
            }
            $avg = $this->fetchEnergyCharts($code, $today);
            if ($avg !== null) {
                $prices[$zone] = $avg;
            }
            sleep(self::ENERGY_PAUSE);
        }

        if (!isset($prices['PL'])) {
            throw new RuntimeException('Brak ceny dla Polski w Nord Pool');
        }

        $eurPln = (float) ($this->http->get(self::NBP_EUR_URL)['rates'][0]['mid'] ?? 0);

        $zones = [];
        foreach ($prices as $zone => $avg) {
            [, , $country, $lon, $lat] = self::ZONES[$zone];
            $zones[] = ['zone' => $zone, 'country' => $country, 'lon' => $lon, 'lat' => $lat, 'avg' => round($avg, 2)];
        }

        $countries = [];
        foreach ($zones as $z) {
            $countries[$z['country']][] = $z['avg'];
        }
        $countries = array_map(fn($v) => round(array_sum($v) / count($v), 2), $countries);
        arsort($countries);

        $pl = $prices['PL'];
        return [
            'date'      => $today,
            'zones'     => $zones,
            'countries' => $countries,
            'eurPln'    => $eurPln,
            'pl'        => [
                'eurMwh'  => round($pl, 2),
                'plnKwh'  => $eurPln > 0 ? round($pl * $eurPln / 1000, 3) : null,
                'rank'    => array_search('PL', array_keys($countries), true) + 1,
                'of'      => count($countries),
                'hours'   => $plHours,
            ],
        ];
    }

    /**
     * Pola dla plugins/electricity.json: nagłówek z ceną w Polsce, mapa (punkty, paleta, kontury po nazwie)
     * i wiersze tabel obok mapy. Skala kolorów od 15. do 90. percentyla — skrajności (np. 15 € w Finlandii)
     * nie spłaszczają reszty Europy.
     */
    public function present(array $data): array
    {
        $pl        = $data['pl'];
        $countries = $data['countries'];
        $values    = array_column($data['zones'], 'avg');
        sort($values);
        $min = $values[(int) floor((count($values) - 1) * 0.15)];
        $max = $values[(int) ceil((count($values) - 1) * 0.90)];

        $withData = array_merge(array_keys($countries), self::ZONE_COUNTRIES_EXTRA);
        $cheap    = array_slice(array_reverse($countries, true), 0, 3, true);
        $pricy    = array_slice($countries, 0, 3, true);
        $row      = fn(string $iso, float $v) => ['cells' => [self::COUNTRY_NAMES[$iso] ?? $iso, $this->eur($v, short: true)], 'color' => $this->labelColor($v, $min, $max)];

        return [
            'dateText'  => date('d.m', strtotime($data['date'])),
            'headline'  => 'Polska: ' . ($pl['plnKwh'] !== null ? number_format($pl['plnKwh'], 2, ',', ' ') . ' zł/kWh' : $this->eur($pl['eurMwh'])),
            'subline'   => sprintf('%s hurtowo · %d. najdrożej z %d krajów', $this->eur($pl['eurMwh']), $pl['rank'], $pl['of']),
            'map'       => [
                'points'  => $this->mapPoints($data['zones'], $countries, $min, $max),
                'palette' => $this->palette($min, $max),
                'area'    => 'europe:' . implode(',', $withData),
                'land'    => 'europe:!' . implode(',', $withData),
                'view'    => self::VIEW,
            ],
            'cheapRows' => array_map($row, array_keys($cheap), $cheap),
            'pricyRows' => array_map($row, array_keys($pricy), $pricy),
            'hourRows'  => $pl['hours'] ? [
                ['cells' => ['najtaniej ' . $pl['hours']['cheapest']['hour'], $this->eur($pl['hours']['cheapest']['price'], short: true)], 'color' => '#44BBFF'],
                ['cells' => ['najdrożej ' . $pl['hours']['priciest']['hour'], $this->eur($pl['hours']['priciest']['price'], short: true)], 'color' => '#FF8800'],
            ] : null,
        ] + $data;
    }

    /** @return array{0: array<string, float>, 1: ?array} [strefa → średnia dnia, najtańsza/najdroższa godzina w PL] */
    private function fetchNordPool(string $date): array
    {
        $areas = array_values(array_map(fn($z) => $z[1], array_filter(self::ZONES, fn($z) => $z[0] === 'np')));
        $data  = $this->http->get(self::NORDPOOL_URL . '?' . http_build_query([
            'date'         => $date,
            'market'       => 'DayAhead',
            'deliveryArea' => implode(',', $areas),
            'currency'     => 'EUR',
        ]));

        $byCode = array_flip(array_map(fn($z) => $z[1], array_filter(self::ZONES, fn($z) => $z[0] === 'np')));
        $prices = [];
        foreach ($data['areaAverages'] ?? [] as $a) {
            if (isset($byCode[$a['areaCode']]) && is_numeric($a['price'])) {
                $prices[$byCode[$a['areaCode']]] = (float) $a['price'];
            }
        }

        // Godzinowe średnie dla Polski z wpisów 15-minutowych
        $hours = [];
        foreach ($data['multiAreaEntries'] ?? [] as $e) {
            if (!isset($e['entryPerArea']['PL'])) {
                continue;
            }
            $hour = (new \DateTimeImmutable($e['deliveryStart']))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('H');
            $hours[$hour][] = (float) $e['entryPerArea']['PL'];
        }
        $hours   = array_map(fn($v) => array_sum($v) / count($v), $hours);
        $plHours = null;
        if ($hours) {
            asort($hours);
            $cheap   = array_key_first($hours);
            $pricy   = array_key_last($hours);
            $plHours = [
                'cheapest' => ['hour' => sprintf('%s:00', $cheap), 'price' => round($hours[$cheap], 2)],
                'priciest' => ['hour' => sprintf('%s:00', $pricy), 'price' => round($hours[$pricy], 2)],
            ];
        }

        Console::success('ElectricityPlugin: Nord Pool — ' . count($prices) . ' stref');
        return [$prices, $plHours];
    }

    /** Średnia dnia z Energy-Charts; przy limicie (HTTP 429) jedna ponowna próba po pauzie. Brak danych = null. */
    private function fetchEnergyCharts(string $zone, string $date): ?float
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $data = $this->http->get(self::ENERGY_URL . '?' . http_build_query(['bzn' => $zone]));
                break;
            } catch (\Throwable $e) {
                if ($attempt === 0 && str_contains($e->getMessage(), 'HTTP 429')) {
                    Console::debug("ElectricityPlugin: limit Energy-Charts przy {$zone}, czekam 30 s");
                    sleep(30);
                    continue;
                }
                Console::warn("ElectricityPlugin: {$zone} — {$e->getMessage()}");
                return null;
            }
        }

        $tz     = new \DateTimeZone(self::TIMEZONE);
        $values = [];
        foreach ($data['unix_seconds'] ?? [] as $i => $ts) {
            $price = $data['price'][$i] ?? null;
            if ($price !== null && (new \DateTimeImmutable("@{$ts}"))->setTimezone($tz)->format('Y-m-d') === $date) {
                $values[] = (float) $price;
            }
        }
        return $values ? array_sum($values) / count($values) : null;
    }

    /** Punkty IDW (każda strefa) + etykiety (jedna na kraj, przy pierwszej strefie; Polska zawsze). */
    private function mapPoints(array $zones, array $countries, float $min, float $max): array
    {
        $labelled = [];
        $points   = [];
        foreach ($zones as $z) {
            $point = ['lon' => $z['lon'], 'lat' => $z['lat'], 'value' => $z['avg']];
            if (!isset($labelled[$z['country']])) {
                $labelled[$z['country']] = true;
                $value = $countries[$z['country']];
                $point += [
                    'label'    => $z['country'] === 'PL' ? 'Polska ' . round($value) : $z['country'] . ' ' . round($value),
                    'color'    => $this->labelColor($value, $min, $max),
                    'priority' => in_array($z['country'], self::ALWAYS_LABELLED, true),
                ];
            }
            $points[] = $point;
        }
        return $points;
    }

    /**
     * Kolory jak na mapie pogody, rozciągnięte na dzisiejszy zakres cen (mapa zawsze ma kontrast).
     * Mocniej kryjące niż w pogodzie — duże, ciemne tło Europy przy słabym kryciu dawało brązowe błoto.
     */
    private function palette(float $min, float $max): array
    {
        $at = fn(float $t) => $min + ($max - $min) * $t;
        return [
            [$at(0.0),  [80, 120, 255], 55],
            [$at(0.25), [100, 180, 255], 60],
            [$at(0.5),  [100, 200, 100], 60],
            [$at(0.75), [255, 200, 0], 55],
            [$at(0.9),  [255, 140, 0], 50],
            [$at(1.0),  [255, 80, 80], 50],
        ];
    }

    private function labelColor(float $value, float $min, float $max): string
    {
        $t = $max > $min ? ($value - $min) / ($max - $min) : 0.5;
        return match (true) {
            $t >= 0.9  => '#FF4444',
            $t >= 0.75 => '#FF8800',
            $t >= 0.6  => '#FFCC00',
            $t >= 0.4  => '#88DD00',
            $t >= 0.2  => '#44BBFF',
            default    => '#AADDFF',
        };
    }

    private function eur(float $value, bool $short = false): string
    {
        return number_format($value, $short ? 0 : 1, ',', ' ') . ($short ? ' €' : ' €/MWh');
    }
}
