<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;
use ZipArchive;

/**
 * Lód morski w Arktyce i wokół Antarktydy z NSIDC Sea Ice Index (G02135, v4): dzienny zasięg lodu w mln km²,
 * porównanie ze średnią 1981–2010 i z tym samym dniem w poprzednich latach, a do rysunku — siatka zasięgu
 * z ostatniego dnia (GeoTIFF, 25 km, rzut polarny stereograficzny NSIDC) i mediana granicy lodu 1981–2010
 * na ten dzień roku (shapefile). Obie półkule pobierane osobno. Wygląd — dwa komponenty ArcticGlobe obok siebie.
 */
class SeaIcePlugin extends AbstractPlugin
{
    private const BASE = 'https://noaadata.apps.nsidc.org/NOAA/G02135/%s/daily';

    private const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Siatki NSIDC Polar Stereographic (EPSG:3411 / 3412): prefiks plików, lewy górny róg (m); komórka 25 km */
    private const HEMISPHERES = [
        'north' => ['N', -3850000, 5850000],
        'south' => ['S', -3950000, 4350000],
    ];
    private const CELL = 25000;

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("SeaIcePlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Lód morski'; }

    public function getCredit(): string { return 'NSIDC'; }

    public function fetchData(): array
    {
        Console::plugin("SeaIcePlugin: pobieram zasięg lodu z NSIDC (Arktyka i Antarktyda)...");
        $data = [];
        foreach (array_keys(self::HEMISPHERES) as $hemisphere) {
            $data[$hemisphere] = $this->hemisphere($hemisphere);
            Console::success(sprintf('SeaIcePlugin: %s %s — %.2f mln km² (średnio %.2f)',
                $hemisphere, $data[$hemisphere]['date'], $data[$hemisphere]['extent'], $data[$hemisphere]['average']));
        }
        return $data;
    }

    /** Zasięg, średnia, historia tego dnia, siatka i mediana dla jednej półkuli. */
    private function hemisphere(string $hemisphere): array
    {
        $prefix = self::HEMISPHERES[$hemisphere][0];
        $base   = sprintf(self::BASE, $hemisphere);

        // Seria dzienna: Year, Month, Day, Extent, Missing, Source — od 1978, do 1987 co drugi dzień
        $series = [];
        foreach (preg_split('/\r?\n/', $this->http->getText("{$base}/data/{$prefix}_seaice_extent_daily_v4.0.csv")) as $line) {
            $cols = array_map('trim', explode(',', $line));
            if (count($cols) >= 4 && ctype_digit($cols[0]) && is_numeric($cols[3])) {
                $series[sprintf('%s-%02d-%02d', $cols[0], $cols[1], $cols[2])] = (float) $cols[3];
            }
        }
        if (!$series) {
            throw new RuntimeException("NSIDC ({$hemisphere}): pusta seria zasięgu lodu");
        }
        $date   = array_key_last($series);
        $extent = $series[$date];
        $doy    = (int) date('z', strtotime($date)) + 1;

        // Ten sam dzień w każdym roku (we wczesnych latach brakujący dzień → sąsiedni)
        $years = [];
        for ($y = 1979; $y <= (int) substr($date, 0, 4); $y++) {
            $md = substr($date, 4);
            $years[$y] = $series[$y . $md]
                ?? $series[date('Y-m-d', strtotime($y . $md . ' -1 day'))]
                ?? $series[date('Y-m-d', strtotime($y . $md . ' +1 day'))]
                ?? null;
        }

        // Klimatologia 1981–2010: DOY, średnia, odchylenie, percentyle
        $average = null;
        foreach (preg_split('/\r?\n/', $this->http->getText("{$base}/data/{$prefix}_seaice_extent_climatology_1981-2010_v4.0.csv")) as $line) {
            $cols = array_map('trim', explode(',', $line));
            if (ctype_digit($cols[0] ?? '') && (int) $cols[0] === $doy) {
                $average = (float) $cols[1];
            }
        }
        if ($average === null) {
            throw new RuntimeException("NSIDC ({$hemisphere}): brak średniej 1981–2010 dla dnia roku {$doy}");
        }

        return [
            'date'    => $date,
            'extent'  => $extent,
            'average' => $average,
            'years'   => array_filter($years, fn($v) => $v !== null),
            'grid'    => $this->grid($hemisphere, $date),
            'median'  => $this->median($hemisphere, $doy),
        ];
    }

    /**
     * Zasięg lodu z GeoTIFF-a (nieskompresowany, 8 bitów, paski wierszy): 0 ocean, 1 lód, 253/254 brzeg i ląd,
     * 251 dziura przy biegunie (pod satelitą — zawsze lód). Do cache trafia jako bajty 0/1, zgzipowane w base64.
     */
    private function grid(string $hemisphere, string $date): array
    {
        [$prefix, $x0, $y0] = self::HEMISPHERES[$hemisphere];
        [$y, $m, $d] = explode('-', $date);
        $month = $m . '_' . self::MONTHS_EN[(int) $m - 1];
        $tif   = $this->http->getText(sprintf(self::BASE, $hemisphere) . "/geotiff/{$y}/{$month}/{$prefix}_{$y}{$m}{$d}_extent_v4.0.tif");

        if (substr($tif, 0, 4) !== "II*\0") {
            throw new RuntimeException('NSIDC: GeoTIFF w nieoczekiwanym formacie');
        }
        $u16  = fn(int $o) => unpack('v', $tif, $o)[1];
        $u32  = fn(int $o) => unpack('V', $tif, $o)[1];
        $ifd  = $u32(4);
        $tags = [];
        for ($i = 0, $n = $u16($ifd); $i < $n; $i++) {
            $e     = $ifd + 2 + $i * 12;
            $type  = $u16($e + 2);
            $count = $u32($e + 4);
            if ($type !== 3 && $type !== 4) {
                continue;   // georeferencja (double, ASCII) — znamy ją z opisu siatki
            }
            $size  = $type === 3 ? 2 : 4;
            $at    = $count * $size <= 4 ? $e + 8 : $u32($e + 8);
            $tags[$u16($e)] = array_map(fn($k) => $size === 2 ? $u16($at + $k * 2) : $u32($at + $k * 4), range(0, min($count, 4096) - 1));
        }
        [$width, $height] = [$tags[256][0], $tags[257][0]];
        if (($tags[259][0] ?? 1) !== 1 || ($tags[258][0] ?? 8) !== 8) {
            throw new RuntimeException('NSIDC: GeoTIFF skompresowany albo nie 8-bitowy');
        }

        $raw = '';
        foreach ($tags[273] as $k => $offset) {
            $raw .= substr($tif, $offset, $tags[279][$k]);
        }
        $cells = strtr(substr($raw, 0, $width * $height), [chr(251) => chr(1)]);
        $cells = preg_replace('/[^\x01]/', "\0", $cells);

        return [
            'pole'   => $hemisphere,
            'width'  => $width,
            'height' => $height,
            'x0'     => $x0,
            'y0'     => $y0,
            'cell'   => self::CELL,
            'data'   => base64_encode(gzcompress($cells, 9)),
        ];
    }

    /**
     * Mediana granicy lodu 1981–2010 na ten dzień roku: polilinie z shapefile'a (typ 3, metry w rzucie NSIDC)
     * przeliczone na [lat, lon].
     * @return list<list<array{float, float}>>
     */
    private function median(string $hemisphere, int $doy): array
    {
        $zip  = $this->http->getText(sprintf('%s/shapefiles/dayofyear_median/median_extent_%s_%03d_1981-2010_polyline_v4.0.zip',
            sprintf(self::BASE, $hemisphere), self::HEMISPHERES[$hemisphere][0], $doy));
        $file = tempnam(sys_get_temp_dir(), 'ice');
        file_put_contents($file, $zip);
        $archive = new ZipArchive();
        $shp     = null;
        if ($archive->open($file) === true) {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                if (str_ends_with($archive->getNameIndex($i), '.shp')) {
                    $shp = $archive->getFromIndex($i);
                }
            }
            $archive->close();
        }
        unlink($file);
        if (!$shp) {
            throw new RuntimeException('NSIDC: brak pliku .shp w archiwum mediany');
        }

        $lines = [];
        for ($o = 100; $o + 8 <= strlen($shp);) {
            $length = unpack('N', $shp, $o + 4)[1] * 2;
            $rec    = $o + 8;
            $o      = $rec + $length;
            if (unpack('V', $shp, $rec)[1] !== 3) {
                continue;
            }
            $parts  = unpack('V', $shp, $rec + 36)[1];
            $points = unpack('V', $shp, $rec + 40)[1];
            $starts = array_values(unpack("V{$parts}", $shp, $rec + 44));
            $coords = array_values(unpack('e' . ($points * 2), $shp, $rec + 44 + 4 * $parts));
            $starts[] = $points;
            for ($p = 0; $p < $parts; $p++) {
                $line = [];
                for ($k = $starts[$p]; $k < $starts[$p + 1]; $k++) {
                    $line[] = array_map(fn($v) => round($v, 2), self::toLatLon($coords[2 * $k], $coords[2 * $k + 1], $hemisphere === 'south'));
                }
                if (count($line) > 1) {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }

    /**
     * Odwrotny rzut polarny stereograficzny NSIDC (elipsoida Hughes 1980, |φc| = 70°) — Snyder 21-33.
     * Północ: λ0 = −45°. Południe: λ0 = 0°, wzór północny dla (−φ) z odwróconą osią y.
     */
    public static function toLatLon(float $x, float $y, bool $south = false): array
    {
        [$a, $e2, $lat0, $lon0] = [6378273.0, 0.006693883, deg2rad(70), deg2rad($south ? 0 : -45)];
        $e   = sqrt($e2);
        $tc  = tan(M_PI / 4 - $lat0 / 2) / ((1 - $e * sin($lat0)) / (1 + $e * sin($lat0))) ** ($e / 2);
        $mc  = cos($lat0) / sqrt(1 - $e2 * sin($lat0) ** 2);
        $t   = hypot($x, $y) * $tc / ($a * $mc);
        $chi = M_PI / 2 - 2 * atan($t);
        $lat = $chi + ($e2 / 2 + 5 * $e2 ** 2 / 24 + $e2 ** 3 / 12) * sin(2 * $chi)
             + (7 * $e2 ** 2 / 48 + 29 * $e2 ** 3 / 240) * sin(4 * $chi)
             + (7 * $e2 ** 3 / 120) * sin(6 * $chi);
        $lon = $lon0 + atan2($x, $south ? $y : -$y);
        return [rad2deg($south ? -$lat : $lat), rad2deg(atan2(sin($lon), cos($lon)))];
    }

    /** Pola dla plugins/seaIce.json: wspólna data i dla każdej półkuli zasięg, różnica, miejsce w historii, glob. */
    public function present(array $data): array
    {
        return [
            'dateText' => date('d.m.Y', strtotime($data['north']['date'])),
            'north'    => $this->presentHemisphere($data['north'], 'Arktyka', [['lat' => 52.1, 'lon' => 19.4, 'label' => 'Polska']]),
            'south'    => $this->presentHemisphere($data['south'], 'Antarktyda', []),
        ];
    }

    private function presentHemisphere(array $h, string $name, array $markers): array
    {
        $num      = fn(float $v) => number_format($v, 2, ',', ' ');
        $diff     = $h['extent'] - $h['average'];
        $pct      = $h['average'] ? $diff / $h['average'] * 100 : 0;
        $year     = (int) substr($h['date'], 0, 4);
        $sorted   = $h['years'];
        asort($sorted);
        $rank     = array_search($year, array_keys($sorted), true);
        $lastYear = $h['years'][$year - 1] ?? null;

        return [
            'name'       => $name,
            'extentText' => $num($h['extent']) . ' mln km²',
            'diffText'   => sprintf('%s%s mln km² (%s%d%%) vs średnia',
                $diff < 0 ? '−' : '+', $num(abs($diff)), $diff < 0 ? '−' : '+', (int) round(abs($pct))),
            'diffColor'  => $diff < 0 ? '#F87171' : '#7DD3FC',
            'rankText'   => $rank === false ? null : sprintf('%d. najmniejszy od %d', $rank + 1, array_key_first($h['years']))
                . ($lastYear !== null ? ' · rok temu ' . $num($lastYear) : ''),
            'grid'       => $h['grid'],
            'lines'      => array_map(fn($line) => ['color' => '#FBBF24', 'dashed' => true, 'points' => $line], $h['median']),
            'markers'    => $markers,
        ];
    }
}
