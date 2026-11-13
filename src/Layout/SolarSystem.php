<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Układ Słoneczny w rzucie 3D (prawdziwa skala w au): płaszczyzna ekliptyki z okręgami co 50 au, orbity planet,
 * heliopauza jako sfera i trajektorie sond z kreską do płaszczyzny — po niej widać, czy sonda jest nad, czy pod
 * ekliptyką. Odcinki pod płaszczyzną są przygaszone. Kamera patrzy z ukosa (elevation) i obraca się tak,
 * żeby sondy leciały w prawo (azimuth = null), a kadr obejmuje Słońce, Neptuna i całe trajektorie.
 */
final class SolarSystem implements Component
{
    private const PAD_X = 70;    // miejsce na podpisy po bokach
    private const PAD_Y = 34;
    private const STARS = 160;

    /**
     * @param list<array{label: string, color: string, track: list<array{float, float, float}>, marks?: list<array{label: string, at: array}>}> $probes
     *        trajektorie w au, heliocentryczne w ekliptyce; ostatni punkt to dzisiejsza pozycja
     * @param list<array{label: string, radius: float, angle: float, pos?: array, orbit?: list<array>, color?: string, size?: int, labeled?: bool}> $planets
     *        orbita kołowa i długość ekliptyczna (°); opcjonalnie prawdziwa orbita (punkty [x, y, z] w au), dzisiejsza
     *        pozycja [x, y, z], kolor i wielkość kropki oraz podpis przy każdej planecie (bez labeled — tylko najdalsza)
     * @param string $radial 'linear' — prawdziwa skala; 'sqrt' / 'log' — odległość od Słońca ściśnięta pierwiastkiem
     *        albo logarytmem ln(1 + r / 0,3 au), żeby Merkury i Neptun zmieściły się na jednym rysunku
     *        (kierunki bez zmian, podpisy okręgów dalej w au)
     */
    public function __construct(
        private readonly array $probes = [],
        private readonly array $planets = [],
        private readonly float $elevation = 24,
        private readonly ?float $azimuth = null,
        private readonly array $rings = [50, 100, 150],
        private readonly float $heliopause = 120,
        private readonly int $maxHeight = 0,
        private readonly string $radial = 'linear',
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $w = $bounds->width;
        $h = $this->maxHeight > 0 ? min($this->maxHeight, $bounds->height) : $bounds->height;
        if ($h < 80) {
            return 0;
        }

        [$cos, $sin] = $this->rotation();
        $el    = deg2rad($this->elevation);
        $warp  = fn(float $r): float => match ($this->radial) {
            'sqrt'  => sqrt(max(0, $r)),
            'log'   => log(1 + max(0, $r) / 0.3),
            default => $r,
        };
        $flat  = function (float $x, float $y, float $z) use ($cos, $sin, $el, $warp): array {
            $r = sqrt($x * $x + $y * $y + $z * $z);
            if ($r > 0 && $this->radial !== 'linear') {
                $k = $warp($r) / $r;
                [$x, $y, $z] = [$x * $k, $y * $k, $z * $k];
            }
            return [$x * $cos - $y * $sin, ($x * $sin + $y * $cos) * sin($el) + $z * cos($el)];
        };

        // Kadr: Słońce, orbita najdalszej planety (prawdziwa, jeśli podana) i trajektorie
        $pts = [[0, 0]];
        $far = max(array_column($this->planets, 'radius') ?: [0]);
        foreach ($this->planets as $pl) {
            foreach ($pl['orbit'] ?? [] as [$x, $y, $z]) {
                $pts[] = $flat($x, $y, $z);
            }
        }
        for ($a = 0; $a < 360; $a += 30) {
            $pts[] = $flat($far * cos(deg2rad($a)), $far * sin(deg2rad($a)), 0);
        }
        foreach ($this->probes as $p) {
            foreach ($p['track'] as [$x, $y, $z]) {
                $pts[] = $flat($x, $y, $z);
                $pts[] = $flat($x, $y, 0);
            }
        }
        [$minX, $maxX] = [min(array_column($pts, 0)), max(array_column($pts, 0))];
        [$minY, $maxY] = [min(array_column($pts, 1)), max(array_column($pts, 1))];
        $scale = min(($w - 2 * self::PAD_X) / max(1, $maxX - $minX), ($h - 2 * self::PAD_Y) / max(1, $maxY - $minY));
        $ox    = $bounds->x + $w / 2 - ($minX + $maxX) / 2 * $scale;
        $oy    = $bounds->y + $h / 2 + ($minY + $maxY) / 2 * $scale;
        $screen = function (float $x, float $y, float $z) use ($flat, $scale, $ox, $oy): array {
            [$sx, $sy] = $flat($x, $y, $z);
            return [(int) round($ox + $sx * $scale), (int) round($oy - $sy * $scale)];
        };
        [$cx, $cy] = $screen(0, 0, 0);

        imagesetclip($img, $bounds->x, $bounds->y, $bounds->x + $w - 1, $bounds->y + $h - 1);
        imagealphablending($img, true);

        $this->stars($img, $bounds->x, $bounds->y, $w, $h);

        // Płaszczyzna ekliptyki i okręgi odległości. Wypełnienie sięga do dalszego z dwóch: największego okręgu
        // albo prawdziwej orbity najdalszej planety — sama elipsa okręgu zostawiała szczelinę przy orbicie Neptuna
        $ringMax   = max($this->rings ?: [0]);
        $ringBoxes = [];
        $plane     = $this->rgba($img, '#1A2442', 88);
        $outer     = null;
        foreach ($this->planets as $pl) {
            if ($pl['radius'] === $far && count($pl['orbit'] ?? []) >= 3) {
                $outer = $pl['orbit'];
            }
        }
        if ($outer !== null && $far >= $ringMax) {
            imagefilledpolygon($img, array_merge(...array_map(fn($p) => $screen(...$p), $outer)), $plane);
        } else {
            $rp = $warp(max($ringMax, $far)) * $scale;
            imagefilledellipse($img, $cx, $cy, (int) (2 * $rp), (int) (2 * $rp * sin($el)), $plane);
        }
        foreach ($this->rings as $r) {
            $rs = $warp($r) * $scale;
            imageellipse($img, $cx, $cy, (int) (2 * $rs), (int) (2 * $rs * sin($el)), $this->rgba($img, '#2E3B58', 30));
            // Podpis z przodu po lewej — sondy lecą w prawo, więc tam jest wolne miejsce
            $lx = (int) ($cx + $rs * cos(deg2rad(115)));
            $ly = (int) ($cy + $rs * sin($el) * sin(deg2rad(115)));
            GdImageHelper::drawText($img, "{$r} au", $lx - 18, $ly + 18, 13, '#3E4E74', FONT);
            $ringBoxes[] = [$lx - 20, $ly + 4, $lx - 16 + GdImageHelper::textWidth("{$r} au", 13, FONT), $ly + 21];
        }

        // Heliopauza — granica wiatru słonecznego: sfera (zarys) i jej przecięcie z ekliptyką
        if ($this->heliopause > 0) {
            $hr = (int) ($warp($this->heliopause) * $scale);
            $this->dashedEllipse($img, $cx, $cy, $hr, $hr, '#5B4B8A', 60);
            $this->dashedEllipse($img, $cx, $cy, $hr, (int) ($hr * sin($el)), '#5B4B8A', 60);
        }

        // Orbity planet: okrąg w ekliptyce albo prawdziwa elipsa z nachyleniem (orbit: punkty [x, y, z])
        foreach ($this->planets as $pl) {
            $orbitColor = $this->rgba($img, isset($pl['color']) ? $pl['color'] : '#3A4A70', isset($pl['color']) ? 80 : 20);
            if (!empty($pl['orbit'])) {
                imagesetthickness($img, 2);
                $prev = null;
                foreach ([...$pl['orbit'], $pl['orbit'][0]] as $p) {
                    $cur = $screen(...$p);
                    if ($prev) {
                        imageline($img, $prev[0], $prev[1], $cur[0], $cur[1], $orbitColor);
                    }
                    $prev = $cur;
                }
                imagesetthickness($img, 1);
            } else {
                $r = $warp($pl['radius']) * $scale;
                imageellipse($img, $cx, $cy, (int) (2 * $r), (int) (2 * $r * sin($el)), $orbitColor);
            }
        }

        // Słońce
        foreach ([[34, 112], [22, 100], [12, 70]] as [$d, $a]) {
            imagefilledellipse($img, $cx, $cy, $d, $d, $this->rgba($img, '#FFB648', $a));
        }
        imagefilledellipse($img, $cx, $cy, 9, 9, $this->rgba($img, '#FFE7A3', 0));
        GdImageHelper::drawText($img, 'Słońce', $cx - GdImageHelper::textWidth('Słońce', 13, FONT) - 16, $cy - 8, 13, '#8C9AB8', FONT);

        // Dzisiejsze położenie planet: kropka (pos albo kąt na okręgu), kolorowa z poświatą, gdy podano color
        $labels = [];
        foreach ($this->planets as $pl) {
            [$px, $py] = isset($pl['pos'])
                ? $screen(...$pl['pos'])
                : $screen($pl['radius'] * cos(deg2rad($pl['angle'])), $pl['radius'] * sin(deg2rad($pl['angle'])), 0);
            if (isset($pl['color'])) {
                $size = (int) ($pl['size'] ?? 9);
                imagefilledellipse($img, $px, $py, 2 * $size + 8, 2 * $size + 8, $this->rgba($img, $pl['color'], 100));
                imagefilledellipse($img, $px, $py, $size, $size, $this->rgba($img, $pl['color'], 0));
            } else {
                imagefilledellipse($img, $px, $py, 5, 5, $this->rgba($img, '#9FB0D0', 0));
            }
            if (!empty($pl['labeled'])) {
                $labels[] = [$pl, $px, $py];
            } elseif ($pl['radius'] === $far) {
                GdImageHelper::drawText($img, $pl['label'], $px - GdImageHelper::textWidth($pl['label'], 13, FONT) - 8, $py + 5, 13, '#6B7BA0', FONT);
            }
        }
        $this->planetLabels($img, $labels, [$cx, $cy], $ringBoxes, $bounds, $w);

        foreach ($this->probes as $p) {
            $this->probe($img, $p, $screen, $bounds, $w, $h);
        }

        imagesetclip($img, 0, 0, imagesx($img) - 1, imagesy($img) - 1);
        return $h;
    }

    /** Trajektoria, kreska do ekliptyki, przekroczenie heliopauzy i dzisiejsza pozycja z podpisem. */
    private function probe(GdImage $img, array $p, callable $screen, Bounds $bounds, int $w, int $h): void
    {
        $track = $p['track'];
        if (count($track) < 2) {
            return;
        }
        [$x, $y, $z]   = end($track);
        [$nx, $ny]     = $screen($x, $y, $z);
        [$fx, $fy]     = $screen($x, $y, 0);

        // Kreska z dzisiejszej pozycji do płaszczyzny + ślad na płaszczyźnie
        $this->dashedLine($img, $nx, $ny, $fx, $fy, $p['color'], 70);
        imageellipse($img, $fx, $fy, 14, 6, $this->rgba($img, $p['color'], 60));

        // Linia na osobnej warstwie — półprzezroczysta poświata bez ciemniejszych „kropek” na łączeniach odcinków
        $layer = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        foreach ([[9, 108], [3, 0]] as [$thick, $alpha]) {
            imagesetthickness($layer, $thick);
            for ($i = 1; $i < count($track); $i++) {
                // Pod ekliptyką przygaszona — widać, że sonda zeszła pod płaszczyznę
                $below = $track[$i][2] < 0;
                $col   = $this->rgba($layer, $p['color'], $thick > 3 ? $alpha : ($below ? 62 : $alpha));
                [$ax, $ay] = $screen(...$track[$i - 1]);
                [$bx, $by] = $screen(...$track[$i]);
                imageline($layer, $ax, $ay, $bx, $by, $col);
            }
        }
        imagesetthickness($layer, 1);
        imagecopy($img, $layer, $bounds->x, $bounds->y, $bounds->x, $bounds->y, $w, $h);
        imagedestroy($layer);

        foreach ($p['marks'] ?? [] as $mark) {
            [$mx, $my] = $screen(...$mark['at']);
            imageellipse($img, $mx, $my, 13, 13, $this->rgba($img, '#FFFFFF', 20));
            // Na lewo od znacznika — trajektoria biegnie w prawo, więc tekst nie leży na linii
            $tw = GdImageHelper::textWidth($mark['label'], 13, FONT);
            GdImageHelper::drawText($img, $mark['label'], max($bounds->x, $mx - $tw - 12), $my + 5, 13, '#B8A8E8', FONT);
        }

        foreach ([[26, 100], [16, 70]] as [$d, $a]) {
            imagefilledellipse($img, $nx, $ny, $d, $d, $this->rgba($img, $p['color'], $a));
        }
        imagefilledellipse($img, $nx, $ny, 9, 9, $this->rgba($img, '#FFFFFF', 0));

        $label = $p['label'];
        $tw    = GdImageHelper::textWidth($label, 18, FONT_BOLD);
        $tx    = min($nx + 14, $bounds->x + $w - $tw);
        $ty    = $z >= 0 ? $ny - 12 : $ny + 30;
        GdImageHelper::drawText($img, $label, $tx, $ty, 18, $p['color'], FONT_BOLD);
    }

    /**
     * Podpisy planet w ich kolorze: dla każdej próbujemy kolejno miejsca wokół kropki (prawo-góra, lewo-góra,
     * prawo-dół, lewo-dół, wyżej, niżej) i bierzemy pierwsze, które nie nachodzi na wcześniejsze podpisy, Słońce
     * ani podpisy okręgów ($taken).
     * Najpierw planety bliżej Słońca — tam jest ciaśniej.
     */
    private function planetLabels(GdImage $img, array $labels, array $sun, array $taken, Bounds $bounds, int $w): void
    {
        usort($labels, fn($a, $b) => $a[0]['radius'] <=> $b[0]['radius']);
        // Słońce i jego podpis (po lewej, jak w render())
        $sw    = GdImageHelper::textWidth('Słońce', 13, FONT);
        $taken = [...$taken, [$sun[0] - 14, $sun[1] - 14, $sun[0] + 14, $sun[1] + 14], [$sun[0] - $sw - 18, $sun[1] - 23, $sun[0] - 14, $sun[1] - 4]];
        foreach ($labels as [$pl, $px, $py]) {
            $tw    = GdImageHelper::textWidth($pl['label'], 16, FONT_BOLD);
            $th    = 16;
            $gap   = (int) (($pl['size'] ?? 9) / 2) + 5;
            // Najpierw tuż przy kropce, potem coraz dalej
            $spots = [];
            foreach ([0, 14, 28] as $d) {
                $g = $gap + $d;
                array_push($spots,
                    [$px + $g, $py - $g], [$px - $g - $tw, $py - $g],
                    [$px + $g, $py + $g + $th], [$px - $g - $tw, $py + $g + $th],
                    [$px - $tw / 2, $py - $g - 8], [$px - $tw / 2, $py + $g + $th + 8]);
            }
            $pick = $spots[0];
            foreach ($spots as [$tx, $ty]) {
                $box  = [$tx - 2, $ty - $th - 2, $tx + $tw + 2, $ty + 4];
                $free = $box[0] >= $bounds->x && $box[2] <= $bounds->x + $w;
                foreach ($taken as $t) {
                    $free = $free && ($box[2] < $t[0] || $box[0] > $t[2] || $box[3] < $t[1] || $box[1] > $t[3]);
                }
                if ($free) {
                    $pick = [$tx, $ty];
                    break;
                }
            }
            [$tx, $ty] = [(int) $pick[0], (int) $pick[1]];
            $taken[]   = [$tx - 2, $ty - $th - 2, $tx + $tw + 2, $ty + 4];
            GdImageHelper::drawText($img, $pl['label'], $tx, $ty, 16, $pl['color'] ?? '#9FB0D0', FONT_BOLD);
        }
    }

    /** Obrót wokół osi ekliptyki: sondy lecą w prawo i lekko do widza. */
    private function rotation(): array
    {
        if ($this->azimuth !== null) {
            $phi = deg2rad($this->azimuth);
        } else {
            $sx = $sy = 0.0;
            foreach ($this->probes as $p) {
                [$x, $y] = end($p['track']) ?: [0, 0];
                $sx += $x;
                $sy += $y;
            }
            $phi = deg2rad(-18) - atan2($sy, $sx);
        }
        return [cos($phi), sin($phi)];
    }

    /** Gwiazdy w tle — zawsze te same (stały seed), żeby podgląd się nie zmieniał. */
    private function stars(GdImage $img, int $x0, int $y0, int $w, int $h): void
    {
        mt_srand(1977);
        for ($i = 0; $i < self::STARS; $i++) {
            $col = $this->rgba($img, '#C8D4F0', mt_rand(60, 112));
            imagesetpixel($img, $x0 + mt_rand(0, $w - 1), $y0 + mt_rand(0, $h - 1), $col);
        }
        mt_srand();
    }

    private function dashedEllipse(GdImage $img, int $cx, int $cy, int $rx, int $ry, string $hex, int $alpha): void
    {
        $col   = $this->rgba($img, $hex, $alpha);
        $steps = max(60, (int) (($rx + $ry) / 3));
        for ($i = 0; $i < $steps; $i += 2) {
            $a1 = 2 * M_PI * $i / $steps;
            $a2 = 2 * M_PI * ($i + 1) / $steps;
            imageline($img, (int) ($cx + $rx * cos($a1)), (int) ($cy + $ry * sin($a1)), (int) ($cx + $rx * cos($a2)), (int) ($cy + $ry * sin($a2)), $col);
        }
    }

    private function dashedLine(GdImage $img, int $x1, int $y1, int $x2, int $y2, string $hex, int $alpha): void
    {
        $col = $this->rgba($img, $hex, $alpha);
        $len = max(1, hypot($x2 - $x1, $y2 - $y1));
        for ($d = 0; $d < $len; $d += 8) {
            $e = min($len, $d + 4);
            imageline($img, (int) ($x1 + ($x2 - $x1) * $d / $len), (int) ($y1 + ($y2 - $y1) * $d / $len),
                (int) ($x1 + ($x2 - $x1) * $e / $len), (int) ($y1 + ($y2 - $y1) * $e / $len), $col);
        }
    }

    private function rgba(GdImage $img, string $hex, int $alpha): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $alpha)));
    }
}
