<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Globus w rzucie ortograficznym z widokiem na biegun (Arktyka albo — z centerLat < 0 — Antarktyda): ocean, ląd z Natural Earth (geo/world.json) i lód morski
 * z siatki NSIDC (rzut polarny stereograficzny, 25 km) — każdy piksel globusa jest przeliczany na szerokość
 * i długość geograficzną, a potem na komórkę siatki. Na wierzchu siatka co 10°/30°, linie (np. mediana granicy
 * lodu) i podpisane punkty. Brzeg globusa przyciemniony, żeby wyglądał na kulę.
 *
 *   { "type": "ArcticGlobe", "grid": "{{seaIce.north.grid}}", "lines": "{{seaIce.north.lines}}", "markers": "{{seaIce.north.markers}}" }
 *
 * grid: pole (north/south), width, height, x0, y0 (lewy górny róg w m), cell (m), data (base64 z gzcompress, bajt 1 = lód).
 * Rysowany w 2× i zmniejszany — krawędź globusa i linie wychodzą gładkie.
 */
final class ArcticGlobe implements Component
{
    private const SUPERSAMPLE = 2;
    private const MASK_W = 1440;   // maska lądu: 0,25° na piksel
    private const MASK_H = 720;

    private static ?GdImage $mask = null;

    /**
     * @param array{width: int, height: int, x0: float, y0: float, cell: float, data: string}|null $grid
     * @param list<array{color: string, dashed?: bool, points: list<array{float, float}>}> $lines punkty [lat, lon]
     * @param list<array{lat: float, lon: float, label?: string}> $markers
     */
    public function __construct(
        private readonly ?array $grid = null,
        private readonly array $lines = [],
        private readonly array $markers = [],
        private readonly float $centerLat = 78,
        private readonly float $centerLon = 20,
        private readonly int $maxHeight = 0,
        private readonly string $ocean = '#0C2452',
        private readonly string $land = '#4B5878',
        private readonly string $ice = '#EAF6FF',
        private readonly string $graticule = '#2B4A85',
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $limit = $this->maxHeight > 0 ? min($this->maxHeight, $bounds->height) : $bounds->height;
        $size  = min($bounds->width, $limit);
        if ($size < 80) {
            return 0;
        }

        $s      = self::SUPERSAMPLE;
        $px     = $size * $s;
        $r      = $px / 2 - 2;
        $c      = $px / 2;
        $canvas = imagecreatetruecolor($px, $px);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $lat0  = deg2rad($this->centerLat);
        $lon0  = deg2rad($this->centerLon);
        $mask  = self::landMask();
        $cells = $this->grid ? gzuncompress(base64_decode($this->grid['data'])) : '';
        $rgb   = fn(string $hex) => [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        [$ocean, $land, $ice] = [$rgb($this->ocean), $rgb($this->land), $rgb($this->ice)];
        [$sin0, $cos0] = [sin($lat0), cos($lat0)];
        $pole  = ($this->grid['pole'] ?? 'north') === 'south' ? -1 : 1;

        // Piksel po pikselu: odwrotny rzut ortograficzny → lat/lon → maska lądu albo komórka siatki lodu
        for ($y = 0; $y < $px; $y++) {
            $ny = ($c - $y - 0.5) / $r;
            for ($x = 0; $x < $px; $x++) {
                $nx  = ($x + 0.5 - $c) / $r;
                $rho = $nx * $nx + $ny * $ny;
                if ($rho > 1) {
                    continue;
                }
                $rho  = sqrt($rho);
                $cosc = sqrt(1 - $rho * $rho);   // cos odległości kątowej od środka = sin c / rho przy rzucie ortograficznym
                $lat  = asin($cosc * $sin0 + $ny * $cos0);
                $lon  = $lon0 + atan2($nx, $cosc * $cos0 - $ny * $sin0);

                $color = $ocean;
                $mx    = (int) ((rad2deg($lon) + 540) * 4) % self::MASK_W;
                $my    = min(self::MASK_H - 1, (int) ((90 - rad2deg($lat)) * 4));
                if (imagecolorat($mask, $mx, $my) & 0xFF) {
                    $color = $land;
                } elseif ($cells !== '' && $lat * $pole > 0.5 && $this->iceAt($cells, $lat, $lon)) {
                    $color = $ice;
                }

                // Przyciemnienie ku brzegowi kuli
                $shade = 0.5 + 0.5 * $cosc;
                imagesetpixel($canvas, $x, $y, ((int) ($color[0] * $shade) << 16) | ((int) ($color[1] * $shade) << 8) | (int) ($color[2] * $shade));
            }
        }
        imagealphablending($canvas, true);

        $project = function (float $lat, float $lon) use ($lat0, $lon0, $sin0, $cos0, $c, $r): ?array {
            $lat = deg2rad($lat);
            $dl  = deg2rad($lon) - $lon0;
            if ($sin0 * sin($lat) + $cos0 * cos($lat) * cos($dl) < 0) {
                return null;   // druga strona kuli
            }
            return [$c + $r * cos($lat) * sin($dl), $c - $r * ($cos0 * sin($lat) - $sin0 * cos($lat) * cos($dl))];
        };

        // Siatka na półkuli widoku: równoleżniki co 10° od 40°, południki co 30°
        imagesetthickness($canvas, $s);
        $grat = $this->rgba($canvas, $this->graticule, 70);
        $hemi = $this->centerLat < 0 ? -1 : 1;
        for ($lat = 40; $lat <= 80; $lat += 10) {
            $this->polyline($canvas, array_map(fn($lon) => $project($hemi * $lat, $lon), range(-180, 180, 3)), $grat);
        }
        for ($lon = -180; $lon < 180; $lon += 30) {
            $this->polyline($canvas, array_map(fn($lat) => $project($hemi * $lat, $lon), range(40, 90, 2)), $grat);
        }

        // Linie (mediana granicy lodu) — przerywane, żeby nie myliły się z brzegiem lodu
        imagesetthickness($canvas, 2 * $s);
        foreach ($this->lines as $line) {
            $color = $this->rgba($canvas, $line['color'], 0);
            $this->polyline($canvas, array_map(fn($p) => $project((float) $p[0], (float) $p[1]), $line['points']), $color, !empty($line['dashed']) ? 7 * $s : 0);
        }
        imagesetthickness($canvas, 1);

        // Poświata na brzegu kuli
        for ($k = 0; $k < 4 * $s; $k++) {
            imageellipse($canvas, (int) $c, (int) $c, (int) (2 * $r - $k), (int) (2 * $r - $k), $this->rgba($canvas, '#7DD3FC', min(127, 60 + $k * 8)));
        }

        $x0 = $bounds->centerX() - intdiv($size, 2);
        imagealphablending($img, true);
        imagecopyresampled($img, $canvas, $x0, $bounds->y, 0, 0, $size, $size, $px, $px);

        foreach ($this->markers as $m) {
            $p = $project((float) $m['lat'], (float) $m['lon']);
            if ($p === null) {
                continue;
            }
            $mx = $x0 + (int) round($p[0] / $s);
            $my = $bounds->y + (int) round($p[1] / $s);
            imagefilledellipse($img, $mx, $my, 9, 9, GdImageHelper::hexToGdColor($img, '#F87171'));
            if (!empty($m['label'])) {
                GdImageHelper::drawText($img, $m['label'], $mx + 9, $my + 6, 16, '#E2E8F0', FONT);
            }
        }

        return $size;
    }

    /**
     * Czy w tym miejscu jest lód: rzut polarny stereograficzny NSIDC (Hughes 1980, |φc| = 70°).
     * Północ (EPSG:3411): λ0 = −45°, y = −ρ·cos. Południe (EPSG:3412): wzór dla −φ, λ0 = 0°, y = +ρ·cos.
     */
    private function iceAt(string $cells, float $lat, float $lon): bool
    {
        $south = ($this->grid['pole'] ?? 'north') === 'south';
        if ($south) {
            $lat = -$lat;
        }
        static $k = null;
        if ($k === null) {
            $e  = sqrt(0.006693883);
            $tc = tan(M_PI / 4 - deg2rad(70) / 2) / ((1 - $e * sin(deg2rad(70))) / (1 + $e * sin(deg2rad(70)))) ** ($e / 2);
            $mc = cos(deg2rad(70)) / sqrt(1 - 0.006693883 * sin(deg2rad(70)) ** 2);
            $k  = [$e, 6378273.0 * $mc / $tc];
        }
        [$e, $scale] = $k;
        $es  = $e * sin($lat);
        $rho = $scale * tan(M_PI / 4 - $lat / 2) / ((1 - $es) / (1 + $es)) ** ($e / 2);
        $dl  = $south ? $lon : $lon + deg2rad(45);
        $col = (int) floor(($rho * sin($dl) - $this->grid['x0']) / $this->grid['cell']);
        $row = (int) floor(($this->grid['y0'] + ($south ? -1 : 1) * $rho * cos($dl)) / $this->grid['cell']);
        if ($col < 0 || $row < 0 || $col >= $this->grid['width'] || $row >= $this->grid['height']) {
            return false;
        }
        return $cells[$row * $this->grid['width'] + $col] === "\1";
    }

    /** @param list<array{float, float}|null> $points null = punkt po drugiej stronie kuli (przerywa linię) */
    private function polyline(GdImage $img, array $points, int $color, int $dash = 0): void
    {
        $run = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            [$a, $b] = [$points[$i - 1], $points[$i]];
            if ($a === null || $b === null) {
                continue;
            }
            $len  = hypot($b[0] - $a[0], $b[1] - $a[1]);
            $run += $len;
            if ($dash > 0 && (int) ($run / $dash) % 2 === 1) {
                continue;
            }
            imageline($img, (int) round($a[0]), (int) round($a[1]), (int) round($b[0]), (int) round($b[1]), $color);
        }
    }

    /** Maska lądu w rzucie równoodległościowym z geo/world.json — liczona raz na proces. */
    private static function landMask(): GdImage
    {
        if (self::$mask !== null) {
            return self::$mask;
        }
        $geo  = json_decode((string) file_get_contents(ROOT_DIR . '/geo/world.json'), true);
        $mask = imagecreatetruecolor(self::MASK_W, self::MASK_H);
        $on   = imagecolorallocate($mask, 255, 255, 255);
        foreach ($geo['land'] as $polygon) {
            foreach ($polygon as $k => $ring) {
                $pts = [];
                for ($i = 0; $i + 1 < count($ring); $i += 2) {
                    $pts[] = (int) round(($ring[$i] + 180) * 4);
                    $pts[] = (int) round((90 - $ring[$i + 1]) * 4);
                }
                if (count($pts) >= 6) {
                    // Pierścienie wewnętrzne (jeziora) wycinają ląd
                    imagefilledpolygon($mask, $pts, $k === 0 ? $on : 0);
                }
            }
        }
        return self::$mask = $mask;
    }

    private function rgba(GdImage $img, string $hex, int $alpha): int
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocatealpha($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }
}
