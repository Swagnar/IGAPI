<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Gdzie są Voyagery: trajektorie obu sond od startu w 1977 (JPL Horizons, wektory heliocentryczne w ekliptyce J2000),
 * odległość od Ziemi, czas biegu sygnału i prędkość. Wygląd — komponent SolarSystem: rzut 3D Układu Słonecznego
 * w prawdziwej skali, z orbitami planet zewnętrznych i miejscem, w którym sondy przekroczyły heliopauzę.
 */
class VoyagerPlugin extends AbstractPlugin
{
    private const API_URL = 'https://ssd.jpl.nasa.gov/api/horizons.api';

    /** Horizons: id sondy → [nazwa, data startu, kolor, przekroczenie heliopauzy] */
    private const PROBES = [
        '-31' => ['Voyager 1', '1977-09-05', '#FBBF24', '2012-08-25'],
        '-32' => ['Voyager 2', '1977-08-20', '#7DD3FC', '2018-11-05'],
    ];

    private const STEP = '90d';            // krok trajektorii — w tej skali wystarczy kwartał
    private const AU_KM = 149_597_870.7;

    /**
     * Planety zewnętrzne: [nazwa, półoś wielka (au), długość średnia J2000 (°), ruch (°/stulecie)].
     * Orbity jako okręgi w ekliptyce — przy skali ~170 au różnica z elipsą jest niewidoczna.
     */
    private const PLANETS = [
        ['Jowisz', 5.203, 34.40, 3034.75],
        ['Saturn', 9.537, 49.94, 1222.49],
        ['Uran', 19.19, 313.23, 428.48],
        ['Neptun', 30.07, 304.88, 218.46],
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("VoyagerPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Gdzie są Voyagery'; }

    public function getCredit(): string { return 'NASA'; }   // JPL Horizons

    public function fetchData(): array
    {
        Console::plugin("VoyagerPlugin: pobieram trajektorie Voyagerów z JPL Horizons...");
        $today  = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
        $probes = [];
        foreach (self::PROBES as $id => [$name, $launch]) {
            // Trajektoria w au (Słońce w środku) + dzisiejszy stan względem Słońca i Ziemi w km
            $track = $this->vectors($id, '500@10', date('Y-m-d', strtotime($launch . ' +5 days')), $today, self::STEP, 1, 'AU-D');
            $sun   = $this->vectors($id, '500@10', $today, date('Y-m-d', strtotime($today . ' +1 day')), '1d', 3, 'KM-S')[0];
            $earth = $this->vectors($id, '500@399', $today, date('Y-m-d', strtotime($today . ' +1 day')), '1d', 3, 'KM-S')[0];

            $now     = [$sun[1] / self::AU_KM, $sun[2] / self::AU_KM, $sun[3] / self::AU_KM];
            $points  = array_map(fn($r) => [(int) $r[0], round($r[1], 3), round($r[2], 3), round($r[3], 3)], $track);
            $points[] = [(int) $sun[0], round($now[0], 3), round($now[1], 3), round($now[2], 3)];

            $probes[$id] = [
                'track'      => $points,
                'sunKm'      => $sun[8],      // RG — odległość
                'speedKmS'   => $sun[9],      // RR — prędkość oddalania się od Słońca
                'earthKm'    => $earth[8],
                'lightSec'   => $earth[7],    // LT — czas biegu światła
            ];
        }

        Console::success(sprintf('VoyagerPlugin: V1 %.1f mld km, V2 %.1f mld km od Ziemi',
            $probes[-31]['earthKm'] / 1e9, $probes[-32]['earthKm'] / 1e9));
        return ['date' => $today, 'probes' => $probes];
    }

    /**
     * Wiersze z Horizons (VECTORS, CSV): [unix, x, y, z, …]. Tablica 1 to sama pozycja,
     * tablica 3 dodaje prędkość, LT (s), RG (odległość) i RR (prędkość radialna).
     * @return list<list<float>>
     */
    private function vectors(string $id, string $center, string $from, string $to, string $step, int $table, string $units): array
    {
        $url = self::API_URL . '?' . http_build_query([
            'format' => 'json', 'COMMAND' => "'{$id}'", 'OBJ_DATA' => "'NO'", 'MAKE_EPHEM' => "'YES'",
            'EPHEM_TYPE' => "'VECTORS'", 'CENTER' => "'{$center}'", 'START_TIME' => "'{$from}'", 'STOP_TIME' => "'{$to}'",
            'STEP_SIZE' => "'{$step}'", 'VEC_TABLE' => "'{$table}'", 'OUT_UNITS' => "'{$units}'", 'CSV_FORMAT' => "'YES'",
        ]);
        $result = (string) ($this->http->get($url)['result'] ?? '');
        if (!preg_match('/\$\$SOE(.*)\$\$EOE/s', $result, $m)) {
            throw new RuntimeException("JPL Horizons ({$id}): brak efemerydy w odpowiedzi — " . mb_substr(trim($result), 0, 160));
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', trim($m[1])) as $line) {
            $cols = array_map('trim', explode(',', $line));
            if (count($cols) < 5) {
                continue;
            }
            // Kolumna 0 to data juliańska (TDB) — różnica z UTC (~69 s) nie ma tu znaczenia
            $row = [((float) $cols[0] - 2440587.5) * 86400];
            foreach (array_slice($cols, 2) as $value) {
                if ($value !== '') {
                    $row[] = (float) $value;
                }
            }
            $rows[] = $row;
        }
        if (!$rows) {
            throw new RuntimeException("JPL Horizons ({$id}): pusta efemeryda");
        }
        return $rows;
    }

    /** Pola dla plugins/voyager.json: liczby dla obu sond i dane rysunku SolarSystem. */
    public function present(array $data): array
    {
        $at     = strtotime($data['date']);
        $fields = [];
        $probes = [];
        foreach (self::PROBES as $id => [$name, $launch, $color, $heliopause]) {
            $p    = $data['probes'][$id];
            $key  = (string) $id === '-31' ? 'v1' : 'v2';   // klucz '-31' PHP zamienia na int
            $last = end($p['track']);

            $fields[$key] = [
                'name'     => $name,
                'color'    => $color,
                'distance' => number_format($p['earthKm'] / 1e9, 1, ',', ' ') . ' mld km',
                'signal'   => 'od Ziemi · sygnał leci ' . $this->duration($p['lightSec']),
                'speed'    => number_format($p['speedKmS'], 1, ',', ' ') . ' km/s · ' . (int) round(sqrt($last[1] ** 2 + $last[2] ** 2 + $last[3] ** 2)) . ' au od Słońca',
            ];
            $probes[] = [
                'label' => $name,
                'color' => $color,
                'track' => array_map(fn($r) => [$r[1], $r[2], $r[3]], $p['track']),
                'marks' => [['label' => 'heliopauza ' . substr($heliopause, 0, 4), 'at' => $this->positionAt($p['track'], strtotime($heliopause))]],
            ];
        }

        $years = (int) floor(($at - strtotime('1977-08-20')) / (365.25 * 86400));
        $t     = ($at - strtotime('2000-01-01 12:00 UTC')) / (36525 * 86400);   // stulecia od J2000

        return $fields + [
            'years'   => $years,
            'probes'  => $probes,
            'planets' => array_map(fn($pl) => [
                'label'  => $pl[0],
                'radius' => $pl[1],
                'angle'  => fmod($pl[2] + $pl[3] * $t, 360),
            ], self::PLANETS),
        ] + $data;
    }

    /** Pozycja [x, y, z] na trajektorii w chwili $ts — liniowo między punktami. */
    private function positionAt(array $track, int $ts): array
    {
        for ($i = 1; $i < count($track); $i++) {
            if ($track[$i][0] >= $ts) {
                $k = ($ts - $track[$i - 1][0]) / max(1, $track[$i][0] - $track[$i - 1][0]);
                return array_map(fn($j) => round($track[$i - 1][$j] + ($track[$i][$j] - $track[$i - 1][$j]) * $k, 3), [1, 2, 3]);
            }
        }
        $last = end($track);
        return [$last[1], $last[2], $last[3]];
    }

    /** 72 246 s → „20 h 4 min” */
    private function duration(float $seconds): string
    {
        $minutes = (int) round($seconds / 60);
        return intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
    }
}
