<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;
use RuntimeException;

/**
 * Mapa świata w odwzorowaniu Equal Earth (Šavrič, Patterson, Jenny 2018) — równopowierzchniowa jak Goode,
 * ale bez rozcięć: ocean w obrysie globu, siatka co 30°, ląd i granice z Natural Earth 1:110m (geo/world.json).
 *
 * Rysowana w 2× i zmniejszana — GD nie wygładza grubych linii ani kół, a tak krawędzie wychodzą gładkie.
 *
 *   new WorldMap([['lon' => 159.5, 'lat' => -9.3, 'radius' => 18, 'color' => '#EF4444', 'label' => '1']])
 *
 * inset: ['lon' => …, 'lat' => …, 'label' => 'Wyspy Salomona'] → globus lekko się zmniejsza, a w rogu po tej samej
 * stronie co to miejsce (ale go nie zasłaniając) pojawia się jego przybliżenie (~3,5×), połączone krótką linią.
 */
final class WorldMap implements Component
{
    private const SUPERSAMPLE = 2;

    // Współczynniki Equal Earth
    private const A1 = 1.340264;
    private const A2 = -0.081106;
    private const A3 = 0.000893;
    private const A4 = 0.003796;

    /** Zasięg rzutu dla λ = ±π, φ = ±π/2 — z niego proporcje mapy (~2,05 : 1) */
    private const X_MAX = 2.7066297;
    private const Y_MAX = 1.3173627;

    /** Skala globusa, gdy w rogu jest przybliżenie, i wymiary przybliżenia względem całej mapy */
    private const INSET_GLOBE  = 0.84;
    private const INSET_WIDTH  = 0.34;
    private const INSET_HEIGHT = 0.47;
    /** Ile stopni szerokości geograficznej mieści przybliżenie w pionie */
    private const INSET_LAT_SPAN = 22;

    private static ?array $geo = null;

    /**
     * @param list<array{lon: float, lat: float, radius: float, color: string, label?: string}> $markers
     *        radius w pikselach gotowego obrazu; większe znaczniki rysowane pod mniejszymi
     */
    public function __construct(
        private readonly array $markers = [],
        private readonly string $ocean = '#0B1634',
        private readonly string $land = '#1F2C4D',
        private readonly string $coast = '#41588C',
        private readonly string $border = '#2E4068',
        private readonly string $graticule = '#15234A',
        private readonly int $padding = 2,
        private readonly ?array $inset = null,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $width  = $bounds->width;
        $height = (int) round($width * self::Y_MAX / self::X_MAX);
        if ($height > $bounds->height) {
            $height = max(0, $bounds->height);
            $width  = (int) round($height * self::X_MAX / self::Y_MAX);
        }
        if ($width < 50) {
            return 0;
        }

        $s      = self::SUPERSAMPLE;
        $w      = $width * $s;
        $h      = $height * $s;
        $canvas = $this->canvas($w, $h);
        $inset  = $this->insetFocus();
        $scale  = ($w / 2 - $this->padding * $s) / self::X_MAX * ($inset ? self::INSET_GLOBE : 1.0);
        $cy     = $h / 2;
        $cx     = $w / 2;
        $corner = null;

        if ($inset !== null) {
            [$corner, $cx] = $this->insetCorner($inset, $w, $h, $cy, $scale, $s);
        }
        $to = fn(float $lon, float $lat): array => $this->pixel($lon, $lat, $cx, $cy, $scale);

        $this->drawGlobe($canvas, $to, $s);
        $this->drawLand($canvas, $to, $s);
        $this->drawMarkers($canvas, $to, $s);
        $this->clipToGlobe($canvas, $to, $s, (int) $cx);
        if ($inset !== null) {
            $this->drawInset($canvas, $inset, $corner, $to, $s);
        }

        $x = $bounds->x + intdiv($bounds->width - $width, 2);
        imagecopyresampled($img, $canvas, $x, $bounds->y, 0, 0, $width, $height, $width * $s, $height * $s);
        imagedestroy($canvas);

        return $height;
    }

    /** Equal Earth: (lon, lat) w stopniach → współrzędne rzutu (x w prawo, y w górę). */
    public static function project(float $lon, float $lat): array
    {
        $lambda = deg2rad($lon);
        $theta  = asin(sqrt(3) / 2 * sin(deg2rad($lat)));
        $t2     = $theta * $theta;
        $t6     = $t2 * $t2 * $t2;

        $x = 2 * sqrt(3) * $lambda * cos($theta) / (3 * (9 * self::A4 * $t6 * $t2 + 7 * self::A3 * $t6 + 3 * self::A2 * $t2 + self::A1));
        $y = $theta * (self::A4 * $t6 * $t2 + self::A3 * $t6 + self::A2 * $t2 + self::A1);

        return [$x, $y];
    }

    private function pixel(float $lon, float $lat, float $cx, float $cy, float $scale): array
    {
        [$x, $y] = self::project($lon, $lat);
        return [(int) round($cx + $x * $scale), (int) round($cy - $y * $scale)];
    }

    private function canvas(int $w, int $h): GdImage
    {
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagefilledrectangle($canvas, 0, 0, $w, $h, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        return $canvas;
    }

    /** Obrys globu: prawa krawędź (λ = 180°) od bieguna S do N, potem lewa z powrotem. */
    private function outline(callable $to): array
    {
        $outline = [];
        for ($lat = -90; $lat <= 90; $lat += 2) {
            array_push($outline, ...$to(180, $lat));
        }
        for ($lat = 90; $lat >= -90; $lat -= 2) {
            array_push($outline, ...$to(-180, $lat));
        }
        return $outline;
    }

    /** Ocean w obrysie globu + siatka co 30°. */
    private function drawGlobe(GdImage $canvas, callable $to, int $s): void
    {
        $outline = $this->outline($to);
        imagefilledpolygon($canvas, $outline, GdImageHelper::hexToGdColor($canvas, $this->ocean));

        imagesetthickness($canvas, $s);
        $grid = GdImageHelper::hexToGdColor($canvas, $this->graticule);
        for ($lon = -150; $lon <= 150; $lon += 30) {
            $this->polyline($canvas, array_map(fn($lat) => $to($lon, $lat), range(-90, 90, 3)), $grid);
        }
        for ($lat = -60; $lat <= 60; $lat += 30) {
            $this->polyline($canvas, array_map(fn($lon) => $to($lon, $lat), range(-180, 180, 3)), $grid);
        }

        imagesetthickness($canvas, $s);
        imagepolygon($canvas, $outline, GdImageHelper::hexToGdColor($canvas, $this->coast));
        imagesetthickness($canvas, 1);
    }

    private function drawLand(GdImage $canvas, callable $to, int $s): void
    {
        $geo    = self::geo();
        $land   = GdImageHelper::hexToGdColor($canvas, $this->land);
        $ocean  = GdImageHelper::hexToGdColor($canvas, $this->ocean);
        $coast  = GdImageHelper::hexToGdColor($canvas, $this->coast);
        $border = GdImageHelper::hexToGdColor($canvas, $this->border);

        $rings = fn(array $flat): array => array_merge(...array_map(
            fn($pair) => $to($pair[0], $pair[1]),
            array_chunk($flat, 2),
        ));

        foreach ($geo['land'] as $polygon) {
            foreach ($polygon as $i => $ring) {
                if (count($ring) < 6) {
                    continue;
                }
                // Pierwszy pierścień to ląd, kolejne to dziury (jeziora) — te zalewamy oceanem
                imagefilledpolygon($canvas, $rings($ring), $i === 0 ? $land : $ocean);
            }
        }

        imagesetthickness($canvas, $s);
        foreach ($geo['borders'] as $line) {
            $this->polyline($canvas, array_chunk($rings($line), 2), $border);
        }
        foreach ($geo['land'] as $polygon) {
            if (count($polygon[0]) >= 6) {
                imagepolygon($canvas, $rings($polygon[0]), $coast);
            }
        }
        imagesetthickness($canvas, 1);
    }

    private function drawMarkers(GdImage $canvas, callable $to, int $s): void
    {
        $markers = $this->markers;
        usort($markers, fn($a, $b) => $b['radius'] <=> $a['radius']);

        foreach ($markers as $m) {
            [$x, $y] = $to((float) $m['lon'], (float) $m['lat']);
            $this->drawMarker($canvas, $x, $y, max(2.0, (float) $m['radius']) * $s, $m['color'], (string) ($m['label'] ?? ''), $s);
        }
    }

    private function drawMarker(GdImage $canvas, int $x, int $y, float $r, string $color, string $label, int $s): void
    {
        [$red, $green, $blue] = sscanf(ltrim($color, '#'), '%02x%02x%02x');

        // Poświata: półprzezroczyste koła nakładane od największego — sumują się w płynny gradient
        for ($step = 0; $step < 8; $step++) {
            $k = 2.8 - $step * 0.22;
            imagefilledellipse($canvas, $x, $y, (int) ($r * $k * 2), (int) ($r * $k * 2), imagecolorallocatealpha($canvas, $red, $green, $blue, 121));
        }
        imagefilledellipse($canvas, $x, $y, (int) ($r * 2), (int) ($r * 2), imagecolorallocatealpha($canvas, $red, $green, $blue, 10));
        imagesetthickness($canvas, $s);
        imageellipse($canvas, $x, $y, (int) ($r * 2), (int) ($r * 2), imagecolorallocatealpha($canvas, 255, 255, 255, 50));
        imagesetthickness($canvas, 1);

        if ($label !== '') {
            $this->markerLabel($canvas, $label, $x, $y, $r, $s);
        }
    }

    /**
     * Poświata znaczników przy krawędzi wychodzi poza glob — czyścimy wszystko na zewnątrz obrysu
     * (dwa wielokąty: prostokąt płótna minus lewa/prawa połowa globu) i rysujemy krawędź jeszcze raz.
     */
    private function clipToGlobe(GdImage $canvas, callable $to, int $s, int $cx): void
    {
        $w  = imagesx($canvas);
        $h  = imagesy($canvas);
        [, $top]    = $to(0, 90);
        [, $bottom] = $to(0, -90);

        $right = [$cx, 0, $w, 0, $w, $h, $cx, $h, $cx, $bottom];
        $left  = [$cx, 0, 0, 0, 0, $h, $cx, $h, $cx, $bottom];
        for ($lat = -90; $lat <= 90; $lat += 2) {
            array_push($right, ...$to(180, $lat));
            array_push($left, ...$to(-180, $lat));
        }
        array_push($right, $cx, $top);
        array_push($left, $cx, $top);

        imagealphablending($canvas, false);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledpolygon($canvas, $right, $clear);
        imagefilledpolygon($canvas, $left, $clear);
        imagealphablending($canvas, true);

        imagesetthickness($canvas, $s);
        imagepolygon($canvas, $this->outline($to), GdImageHelper::hexToGdColor($canvas, $this->coast));
        imagesetthickness($canvas, 1);
    }

    /** Zweryfikowany punkt przybliżenia albo null (brak / niepełne dane — wtedy zwykła mapa). */
    private function insetFocus(): ?array
    {
        if (!isset($this->inset['lon'], $this->inset['lat']) || !is_numeric($this->inset['lon']) || !is_numeric($this->inset['lat'])) {
            return null;
        }
        return [
            'lon'   => (float) $this->inset['lon'],
            'lat'   => (float) $this->inset['lat'],
            'label' => (string) ($this->inset['label'] ?? ''),
        ];
    }

    /**
     * Róg na przybliżenie i środek globusa (odsuniętego od tego rogu). Kolejność prób: ta sama strona co
     * trzęsienie i przeciwna połowa w pionie (krótka linia), potem pozostałe — pierwszy róg, który nie zasłania
     * ani tego miejsca, ani żadnego numerowanego znacznika.
     *
     * @return array{0: string, 1: float}
     */
    private function insetCorner(array $focus, int $w, int $h, float $cy, float $scale, int $s): array
    {
        [$x, $y] = self::project($focus['lon'], $focus['lat']);
        $side    = $x >= 0 ? 'right' : 'left';
        $vert    = $y >= 0 ? 'bottom' : 'top';
        $flipH   = $side === 'right' ? 'left' : 'right';
        $flipV   = $vert === 'top' ? 'bottom' : 'top';
        $half    = self::X_MAX * $scale + $this->padding * $s;
        $iw      = $w * self::INSET_WIDTH;
        $ih      = $h * self::INSET_HEIGHT;
        $margin  = 30 * $s;

        $keep = [[$focus['lon'], $focus['lat']]];
        foreach ($this->markers as $m) {
            if (!empty($m['label'])) {
                $keep[] = [(float) $m['lon'], (float) $m['lat']];
            }
        }

        foreach (["{$vert}-{$side}", "{$flipV}-{$side}", "{$vert}-{$flipH}", "{$flipV}-{$flipH}"] as $corner) {
            $cx = str_ends_with($corner, 'right') ? $half : $w - $half;
            $ix = str_ends_with($corner, 'right') ? $w - $iw : 0;
            $iy = str_starts_with($corner, 'bottom') ? $h - $ih : 0;

            $covered = false;
            foreach ($keep as [$lon, $lat]) {
                [$px, $py] = $this->pixel($lon, $lat, $cx, $cy, $scale);
                $covered = $covered || ($px > $ix - $margin && $px < $ix + $iw + $margin && $py > $iy - $margin && $py < $iy + $ih + $margin);
            }
            if (!$covered) {
                return [$corner, $cx];
            }
        }
        return ["{$vert}-{$flipH}", str_ends_with($flipH, 'right') ? $half : $w - $half];
    }

    /**
     * Przybliżenie w rogu: lokalne odwzorowanie walcowe (długość × cos szerokości), ląd, granice, znaczniki
     * w powiększeniu i podpis. Wielokąty przesuwamy o ±360°, gdy okno przecina południk 180° (Fidżi, Tonga).
     */
    private function drawInset(GdImage $canvas, array $focus, string $corner, callable $to, int $s): void
    {
        $w  = imagesx($canvas);
        $h  = imagesy($canvas);
        $iw = (int) round($w * self::INSET_WIDTH);
        $ih = (int) round($h * self::INSET_HEIGHT);
        $ix = str_ends_with($corner, 'right') ? $w - $iw : 0;
        $iy = str_starts_with($corner, 'bottom') ? $h - $ih : 0;

        // Linia od punktu na globusie do wewnętrznego rogu przybliżenia (rysowana pod nim)
        [$qx, $qy] = $to($focus['lon'], $focus['lat']);
        $innerX    = str_ends_with($corner, 'right') ? $ix : $ix + $iw;
        $innerY    = str_starts_with($corner, 'bottom') ? $iy : $iy + $ih;
        imagesetthickness($canvas, $s);
        imageline($canvas, $qx, $qy, $innerX, $innerY, imagecolorallocatealpha($canvas, 255, 255, 255, 70));
        imageellipse($canvas, $qx, $qy, 30 * $s, 30 * $s, imagecolorallocatealpha($canvas, 255, 255, 255, 40));
        imagesetthickness($canvas, 1);

        $lat0 = max(-80 + self::INSET_LAT_SPAN / 2, min(80 - self::INSET_LAT_SPAN / 2, $focus['lat']));
        $lon0 = $focus['lon'];
        $k    = $ih / self::INSET_LAT_SPAN;
        $cos  = max(0.2, cos(deg2rad($lat0)));
        $span = $iw / ($k * $cos);
        $west = $lon0 - $span / 2;
        $east = $lon0 + $span / 2;
        $south = $lat0 - self::INSET_LAT_SPAN / 2;
        $north = $lat0 + self::INSET_LAT_SPAN / 2;
        $map  = fn(float $lon, float $lat): array => [
            (int) round($iw / 2 + ($lon - $lon0) * $cos * $k),
            (int) round($ih / 2 - ($lat - $lat0) * $k),
        ];

        $inset = imagecreatetruecolor($iw, $ih);
        imagefilledrectangle($inset, 0, 0, $iw, $ih, GdImageHelper::hexToGdColor($inset, $this->ocean));
        imagealphablending($inset, true);

        // Siatka co 10°
        imagesetthickness($inset, $s);
        $grid = GdImageHelper::hexToGdColor($inset, $this->graticule);
        for ($lon = floor($west / 10) * 10; $lon <= $east; $lon += 10) {
            [$x] = $map($lon, $lat0);
            imageline($inset, $x, 0, $x, $ih, $grid);
        }
        for ($lat = floor($south / 10) * 10; $lat <= $north; $lat += 10) {
            [, $y] = $map($lon0, $lat);
            imageline($inset, 0, $y, $iw, $y, $grid);
        }

        $geo    = self::geo();
        $land   = GdImageHelper::hexToGdColor($inset, $this->land);
        $ocean  = GdImageHelper::hexToGdColor($inset, $this->ocean);
        $coast  = GdImageHelper::hexToGdColor($inset, $this->coast);
        $border = GdImageHelper::hexToGdColor($inset, $this->border);

        // Każda kopia (przesunięcie o -360/0/+360°) kształtu, która wpada w okno przybliżenia
        $visible = function (array $flat) use ($west, $east, $south, $north): array {
            $lons   = array_values(array_filter($flat, fn($i) => $i % 2 === 0, ARRAY_FILTER_USE_KEY));
            $lats   = array_values(array_filter($flat, fn($i) => $i % 2 === 1, ARRAY_FILTER_USE_KEY));
            if (max($lats) < $south || min($lats) > $north) {
                return [];
            }
            return array_values(array_filter([-360, 0, 360], fn($shift) => max($lons) + $shift >= $west && min($lons) + $shift <= $east));
        };
        $points = fn(array $flat, int $shift): array => array_merge(...array_map(
            fn($pair) => $map($pair[0] + $shift, $pair[1]),
            array_chunk($flat, 2),
        ));

        foreach ($geo['land'] as $polygon) {
            if (count($polygon[0]) < 6) {
                continue;
            }
            foreach ($visible($polygon[0]) as $shift) {
                foreach ($polygon as $i => $ring) {
                    if (count($ring) >= 6) {
                        imagefilledpolygon($inset, $points($ring, $shift), $i === 0 ? $land : $ocean);
                    }
                }
                imagepolygon($inset, $points($polygon[0], $shift), $coast);
            }
        }
        foreach ($geo['borders'] as $line) {
            foreach ($visible($line) as $shift) {
                $this->polyline($inset, array_chunk($points($line, $shift), 2), $border);
            }
        }
        imagesetthickness($inset, 1);

        // Znaczniki w powiększeniu
        $markers = $this->markers;
        usort($markers, fn($a, $b) => $b['radius'] <=> $a['radius']);
        foreach ($markers as $m) {
            foreach ([-360, 0, 360] as $shift) {
                $lon = (float) $m['lon'] + $shift;
                if ($lon < $west - 5 || $lon > $east + 5 || $m['lat'] < $south - 5 || $m['lat'] > $north + 5) {
                    continue;
                }
                [$x, $y] = $map($lon, (float) $m['lat']);
                // Mniejsze niż na globusie — przybliżenie ma pokazać geografię, a nie zasłonić ją poświatą
                $this->drawMarker($inset, $x, $y, min(14.0, max(3.0, (float) $m['radius'] * 0.6)) * $s, $m['color'], (string) ($m['label'] ?? ''), $s);
            }
        }

        if ($focus['label'] !== '') {
            $this->insetCaption($inset, $focus['label'], $s);
        }

        imagecopy($canvas, $inset, $ix, $iy, 0, 0, $iw, $ih);
        imagedestroy($inset);

        imagesetthickness($canvas, $s);
        imagerectangle($canvas, $ix + intdiv($s, 2), $iy + intdiv($s, 2), $ix + $iw - $s, $iy + $ih - $s, GdImageHelper::hexToGdColor($canvas, $this->coast));
        imagesetthickness($canvas, 1);
    }

    /** Nazwa rejonu w lewym dolnym rogu przybliżenia, na ciemnym tle. */
    private function insetCaption(GdImage $inset, string $text, int $s): void
    {
        $size = 13 * $s;
        $pad  = 6 * $s;
        $box  = imagettfbbox($size, 0, FONT_BOLD, $text);
        $tw   = $box[2] - $box[0];
        $th   = $box[1] - $box[7];
        $x    = 8 * $s;
        $y    = imagesy($inset) - 8 * $s - $th - 2 * $pad;

        imagefilledrectangle($inset, $x, $y, $x + $tw + 2 * $pad, $y + $th + 2 * $pad, imagecolorallocatealpha($inset, 6, 10, 24, 25));
        imagettftext($inset, $size, 0, $x + $pad - $box[0], $y + $pad + $th, GdImageHelper::hexToGdColor($inset, '#FFFFFF'), FONT_BOLD, $text);
    }

    /** Numer znacznika w ciemnym kółku obok, żeby był czytelny także na małych punktach. */
    private function markerLabel(GdImage $canvas, string $label, int $x, int $y, float $r, int $s): void
    {
        $size  = 13 * $s;
        $badge = (int) (22 * $s);
        $bx    = (int) ($x + $r * 0.75 + $badge * 0.45);
        $by    = (int) ($y - $r * 0.75 - $badge * 0.45);

        imagefilledellipse($canvas, $bx, $by, $badge, $badge, imagecolorallocatealpha($canvas, 6, 10, 24, 15));
        imagesetthickness($canvas, $s);
        imageellipse($canvas, $bx, $by, $badge, $badge, imagecolorallocatealpha($canvas, 255, 255, 255, 30));
        imagesetthickness($canvas, 1);

        $box = imagettfbbox($size, 0, FONT_BOLD, $label);
        $tw  = $box[2] - $box[0];
        $th  = $box[1] - $box[7];
        imagettftext($canvas, $size, 0, (int) ($bx - $tw / 2 - $box[0]), (int) ($by + $th / 2), GdImageHelper::hexToGdColor($canvas, '#FFFFFF'), FONT_BOLD, $label);
    }

    /** @param list<array{0: int, 1: int}> $points */
    private function polyline(GdImage $canvas, array $points, int $color): void
    {
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            imageline($canvas, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $color);
        }
    }

    private static function geo(): array
    {
        if (self::$geo === null) {
            $geo = json_decode((string) @file_get_contents(ROOT_DIR . '/geo/world.json'), true);
            if (!isset($geo['land'], $geo['borders'])) {
                throw new RuntimeException('Brak lub nieprawidłowy geo/world.json');
            }
            self::$geo = $geo;
        }
        return self::$geo;
    }
}
