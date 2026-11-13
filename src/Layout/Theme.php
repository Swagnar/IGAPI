<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

/**
 * Wygląd slajdów: tło „zorza” (gwiazdy i rozmyte pasma w kolorach slajdu) i karty z matowego szkła pod pluginami
 * (rozmyte tło, jasna krawędź, cień, krótki pasek akcentu na górnej krawędzi). Kolory zależą od slajdu (init()).
 */
final class Theme
{
    /** Akcent slajdu: [główny, drugi] */
    private const ACCENTS = [
        'swiat'  => ['#38BDF8', '#6366F1'],
        'kosmos' => ['#A855F7', '#EC4899'],
        'polska' => ['#F43F5E', '#F59E0B'],
    ];

    /** Odstęp treści od krawędzi karty: [bok, góra, dół] */
    public const PADDING = [26, 22, 22];

    private const RADIUS = 30;

    private static string $accent = '#38BDF8';
    private static string $second = '#6366F1';

    public static function init(?string $slide = null): void
    {
        [self::$accent, self::$second] = self::ACCENTS[$slide ?? ''] ?? ['#38BDF8', '#6366F1'];
    }

    // ── tło ──────────────────────────────────────────────────────────────────

    public static function background(GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        self::gradient($img, [2, 6, 16], [1, 2, 6]);

        // Pasma zorzy: sinusoidy z pionowych kresek wygaszanych ku dołowi, rysowane na małym płótnie i rozmyte
        $small = self::smallCopy($img, 16);
        $sw    = imagesx($small);
        $sh    = imagesy($small);
        foreach ([[0.07, self::$accent, 6, 0.0, 14], [0.13, self::$second, 5, 1.7, 10], [0.52, self::$accent, 4, 3.1, 9]] as [$base, $hex, $amp, $phase, $len]) {
            for ($x = 0; $x < $sw; $x++) {
                $y = $base * $sh + sin($x / $sw * 2 * M_PI + $phase) * $amp;
                for ($k = 0; $k < $len; $k++) {
                    imagesetpixel($small, $x, (int) ($y + $k), self::rgba($small, $hex, 70 + (int) (57 * $k / $len)));
                }
            }
        }
        self::blur($small, 25);
        imagecopyresampled($img, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);

        // Gwiazdy (zawsze te same) i drobne ziarno, żeby gradient nie robił pasów
        mt_srand(7);
        for ($i = 0; $i < 260; $i++) {
            imagesetpixel($img, mt_rand(0, $w), mt_rand(0, $h), self::rgba($img, '#FFFFFF', mt_rand(40, 110)));
        }
        for ($i = 0; $i < $w * $h / 6; $i++) {
            imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), self::rgba($img, mt_rand(0, 1) ? '#FFFFFF' : '#000000', 123));
        }
        mt_srand();
    }

    // ── karta pod pluginem ───────────────────────────────────────────────────

    public static function card(GdImage $img, Bounds $b): void
    {
        $r = self::RADIUS;
        self::shadow($img, $b, 18, 60);

        // Matowe szkło: rozmyte to, co pod kartą, lekko rozjaśnione
        $glass = imagecrop($img, ['x' => $b->x, 'y' => $b->y, 'width' => $b->width, 'height' => $b->height]);
        $blur  = self::smallCopy($glass, 10);
        self::blur($blur, 4);
        imagecopyresampled($glass, $blur, 0, 0, 0, 0, $b->width, $b->height, imagesx($blur), imagesy($blur));
        imagefilledrectangle($glass, 0, 0, $b->width, $b->height, self::rgba($glass, '#FFFFFF', 118));
        self::rounded($img, $b, $r, $glass, 100);
        self::border($img, $b, $r, '#FFFFFF', 0.38, 0.08);

        // Pasek akcentu na górnej krawędzi
        $bar = new Bounds($b->x + 28, $b->y, 120, 6);
        self::rounded($img, $bar, 3, self::solid($bar, self::$accent), 100);
    }

    // ── klocki ───────────────────────────────────────────────────────────────

    private static function gradient(GdImage $img, array $top, array $bottom): void
    {
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            imageline($img, 0, $y, imagesx($img), $y, imagecolorallocate($img,
                (int) ($top[0] + ($bottom[0] - $top[0]) * $t), (int) ($top[1] + ($bottom[1] - $top[1]) * $t), (int) ($top[2] + ($bottom[2] - $top[2]) * $t)));
        }
    }

    /** Pomniejszona kopia — rozmycie na małym obrazku i rozciągnięcie jest dużo tańsze niż na pełnym */
    private static function smallCopy(GdImage $img, int $factor): GdImage
    {
        $sw    = max(1, intdiv(imagesx($img), $factor));
        $sh    = max(1, intdiv(imagesy($img), $factor));
        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, imagesx($img), imagesy($img));
        return $small;
    }

    private static function blur(GdImage $img, int $passes): void
    {
        for ($i = 0; $i < $passes; $i++) {
            imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
        }
    }

    private static function rgba(GdImage $img, string $hex, int $alpha): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $alpha)));
    }

    private static function solid(Bounds $b, string $hex): GdImage
    {
        $img = imagecreatetruecolor(max(1, $b->width), max(1, $b->height));
        [$r, $g, $bl] = sscanf($hex, '#%02x%02x%02x');
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $bl));
        return $img;
    }

    /** Pokrycie piksela przez zaokrąglony prostokąt (wygładzone rogi) */
    private static function coverage(int $x, int $y, int $w, int $h, int $r): float
    {
        $cx = $x < $r ? $r : ($x >= $w - $r ? $w - $r - 1 : null);
        $cy = $y < $r ? $r : ($y >= $h - $r ? $h - $r - 1 : null);
        if ($cx === null || $cy === null) {
            return 1.0;
        }
        return max(0.0, min(1.0, $r - hypot($x - $cx, $y - $cy) + 0.5));
    }

    /** Nakłada obraz (rozmiaru $b) na canvas w kształcie zaokrąglonego prostokąta, z kryciem $pct (0–100) */
    private static function rounded(GdImage $img, Bounds $b, int $r, GdImage $src, int $pct): void
    {
        $w = $b->width;
        $h = $b->height;
        $r = min($r, intdiv($w, 2), intdiv($h, 2));
        // Środek bez rogów — hurtem
        imagecopymerge($img, $src, $b->x + $r, $b->y, $r, 0, $w - 2 * $r, $h, $pct);
        imagecopymerge($img, $src, $b->x, $b->y + $r, 0, $r, $r, $h - 2 * $r, $pct);
        imagecopymerge($img, $src, $b->x + $w - $r, $b->y + $r, $w - $r, $r, $r, $h - 2 * $r, $pct);
        // Rogi — piksel po pikselu z wygładzeniem
        foreach ([[0, 0], [$w - $r, 0], [0, $h - $r], [$w - $r, $h - $r]] as [$ox, $oy]) {
            for ($y = $oy; $y < $oy + $r; $y++) {
                for ($x = $ox; $x < $ox + $r; $x++) {
                    $c = self::coverage($x, $y, $w, $h, $r) * $pct / 100;
                    if ($c <= 0) {
                        continue;
                    }
                    $s = imagecolorat($src, $x, $y);
                    $d = imagecolorat($img, $b->x + $x, $b->y + $y);
                    imagesetpixel($img, $b->x + $x, $b->y + $y, imagecolorallocate($img,
                        (int) ((($d >> 16) & 255) * (1 - $c) + (($s >> 16) & 255) * $c),
                        (int) ((($d >> 8) & 255) * (1 - $c) + (($s >> 8) & 255) * $c),
                        (int) (($d & 255) * (1 - $c) + ($s & 255) * $c)));
                }
            }
        }
    }

    /** Krawędź karty 1 px: krycie od $top (góra) do $bottom (dół) */
    private static function border(GdImage $img, Bounds $b, int $r, string $hex, float $top, float $bottom): void
    {
        [$cr, $cg, $cb] = sscanf($hex, '#%02x%02x%02x');
        $w = $b->width;
        $h = $b->height;
        $r = min($r, intdiv($w, 2), intdiv($h, 2));
        $paint = function (int $x, int $y, float $c) use ($img, $b, $h, $top, $bottom, $cr, $cg, $cb) {
            $a = $c * ($top + ($bottom - $top) * $y / max(1, $h));
            if ($a > 0.004) {
                imagesetpixel($img, $b->x + $x, $b->y + $y, imagecolorallocatealpha($img, $cr, $cg, $cb, (int) (127 - min(1, $a) * 127)));
            }
        };
        for ($x = $r; $x < $w - $r; $x++) {
            $paint($x, 0, 1);
            $paint($x, $h - 1, 1);
        }
        for ($y = $r; $y < $h - $r; $y++) {
            $paint(0, $y, 1);
            $paint($w - 1, $y, 1);
        }
        foreach ([[0, 0], [$w - $r, 0], [0, $h - $r], [$w - $r, $h - $r]] as [$ox, $oy]) {
            for ($y = $oy; $y < $oy + $r; $y++) {
                for ($x = $ox; $x < $ox + $r; $x++) {
                    $inner = ($x >= 1 && $y >= 1 && $x < $w - 1 && $y < $h - 1) ? self::coverage($x - 1, $y - 1, $w - 2, $h - 2, $r - 1) : 0;
                    $paint($x, $y, self::coverage($x, $y, $w, $h, $r) - $inner);
                }
            }
        }
    }

    /** Miękki cień pod kartą: przyciemnienie rozmytego prostokąta przesuniętego w dół */
    private static function shadow(GdImage $img, Bounds $b, int $spread, int $strength): void
    {
        $pad  = $spread * 2;
        $sw   = $b->width + 2 * $pad;
        $sh   = $b->height + 2 * $pad;
        $mask = imagecreatetruecolor(intdiv($sw, 4), intdiv($sh, 4));
        imagefilledrectangle($mask, intdiv($pad, 4), intdiv($pad + $spread / 2, 4), intdiv($pad + $b->width, 4), intdiv($pad + $b->height + $spread / 2, 4), imagecolorallocate($mask, 255, 255, 255));
        self::blur($mask, intdiv($spread, 2));
        $x0 = $b->x - $pad;
        $y0 = $b->y - $pad;
        for ($y = 0; $y < $sh; $y += 2) {
            for ($x = 0; $x < $sw; $x += 2) {
                $m  = (imagecolorat($mask, min(imagesx($mask) - 1, intdiv($x, 4)), min(imagesy($mask) - 1, intdiv($y, 4))) & 255) / 255;
                $tx = $x0 + $x;
                $ty = $y0 + $y;
                if ($m < 0.02 || $tx < 0 || $ty < 0 || $tx >= imagesx($img) - 1 || $ty >= imagesy($img) - 1) {
                    continue;
                }
                imagefilledrectangle($img, $tx, $ty, $tx + 1, $ty + 1, imagecolorallocatealpha($img, 0, 0, 0, (int) (127 - $m * $strength)));
            }
        }
    }
}
