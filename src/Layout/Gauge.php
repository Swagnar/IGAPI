<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Licznik w kształcie półkola: pas podzielony na strefy w kolorach, wskazówka i wartość pod osią.
 * Strefa, w której jest wskazówka, świeci pełnym kolorem, pozostałe są przygaszone.
 *
 *   { "type": "Gauge", "value": 29, "label": "Strach", "zones": [{ "to": 25, "color": "#EF4444" }, …] }
 *
 * Rysowany w 2× i zmniejszany — GD nie wygładza łuków, a tak krawędzie wychodzą gładkie.
 */
final class Gauge implements Component
{
    private const SUPERSAMPLE = 2;
    private const BAND = 0.24;        // grubość pasa względem promienia
    private const GAP_DEG = 1.2;      // przerwa między strefami

    /**
     * @param list<array{to: float, color: string}> $zones górne granice stref rosnąco, ostatnia = max
     */
    public function __construct(
        private readonly float $value,
        private readonly array $zones = [],
        private readonly float $min = 0,
        private readonly float $max = 100,
        private readonly string $label = '',
        private readonly ?string $labelColor = null,
        private readonly int $maxHeight = 0,
        private readonly string $scaleColor = '#556688',
        private readonly bool $scale = true,   // podpisy min i max pod końcami łuku
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $limit = $this->maxHeight > 0 ? min($this->maxHeight, $bounds->height) : $bounds->height;
        // Wysokość: półkole (r) + liczba i podpis pod osią (~0,68 r)
        $r = (int) min(($bounds->width - 20) / 2, $limit / 1.68);
        if ($r < 40) {
            return 0;
        }

        $s      = self::SUPERSAMPLE;
        $w      = 2 * $r * $s + 4;
        $h      = (int) (($r + 0.12 * $r) * $s) + 4;
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        $cx    = $w / 2;
        $cy    = $r * $s + 2;
        $outer = $r * $s;
        $inner = $outer * (1 - self::BAND);
        $frac  = fn(float $v) => max(0.0, min(1.0, ($v - $this->min) / (($this->max - $this->min) ?: 1)));
        $angle = fn(float $f) => M_PI * (1 - $f);   // 0 → lewo, 1 → prawo
        $point = fn(float $a, float $radius) => [$cx + $radius * cos($a), $cy - $radius * sin($a)];

        $active = $this->activeZone();
        $from   = $this->min;
        foreach ($this->zones as $i => $zone) {
            $gap = deg2rad(self::GAP_DEG) / 2;
            $a1  = $angle($frac($from)) - ($i > 0 ? $gap : 0);
            $a2  = $angle($frac((float) $zone['to'])) + ($i < count($this->zones) - 1 ? $gap : 0);
            $pts = [];
            $steps = max(4, (int) (($a1 - $a2) * 40));
            for ($k = 0; $k <= $steps; $k++) {
                array_push($pts, ...$point($a1 + ($a2 - $a1) * $k / $steps, $outer));
            }
            for ($k = $steps; $k >= 0; $k--) {
                array_push($pts, ...$point($a1 + ($a2 - $a1) * $k / $steps, $inner));
            }
            imagefilledpolygon($canvas, array_map(fn($v) => (int) round($v), $pts), $this->rgba($canvas, $zone['color'], $i === $active ? 0 : 88));
            $from = (float) $zone['to'];
        }

        // Wskazówka: zwężający się trójkąt od osi do pasa, na osi kółko
        $a     = $angle($frac($this->value));
        $tip   = $point($a, $outer * 0.97);
        $base  = 0.06 * $outer;
        $white = $this->rgba($canvas, '#F1F5F9', 0);
        imagefilledpolygon($canvas, array_map(fn($v) => (int) round($v), [
            $tip[0], $tip[1],
            $cx + $base * cos($a + M_PI / 2), $cy - $base * sin($a + M_PI / 2),
            $cx + $base * cos($a - M_PI / 2), $cy - $base * sin($a - M_PI / 2),
        ]), $white);
        imagefilledellipse($canvas, (int) $cx, (int) $cy, (int) (0.2 * $outer), (int) (0.2 * $outer), $white);
        imagefilledellipse($canvas, (int) $cx, (int) $cy, (int) (0.09 * $outer), (int) (0.09 * $outer), $this->rgba($canvas, '#0B1426', 0));

        $x = $bounds->centerX() - $r - 1;
        $y = $bounds->y;
        imagealphablending($img, true);
        imagecopyresampled($img, $canvas, $x, $y, 0, 0, intdiv($w, $s), intdiv($h, $s), $w, $h);

        // Skala na końcach pasa, liczba i podpis pod osią
        $axis = $y + $r + 1;
        $size = (int) max(14, $r * 0.09);
        if ($this->scale) {
            GdImageHelper::centerText($img, $this->format($this->min), $axis + $size + 8, $size, $this->scaleColor, FONT, (int) ($bounds->centerX() - $r * (1 - self::BAND / 2)));
            GdImageHelper::centerText($img, $this->format($this->max), $axis + $size + 8, $size, $this->scaleColor, FONT, (int) ($bounds->centerX() + $r * (1 - self::BAND / 2)));
        }

        $valueSize = (int) max(24, $r * 0.3);
        GdImageHelper::centerText($img, $this->format($this->value), $axis + (int) ($r * 0.2) + $valueSize, $valueSize, '#FFFFFF', FONT_BOLD, $bounds->centerX());
        if ($this->label !== '') {
            $color = $this->labelColor ?? ($active !== null ? $this->zones[$active]['color'] : '#FFFFFF');
            GdImageHelper::centerText($img, $this->label, $axis + (int) ($r * 0.2) + $valueSize + (int) ($r * 0.06) + (int) max(16, $r * 0.13), (int) max(16, $r * 0.13), $color, FONT_BOLD, $bounds->centerX());
        }

        return (int) ceil($r * 1.68);
    }

    private function activeZone(): ?int
    {
        foreach ($this->zones as $i => $zone) {
            if ($this->value <= (float) $zone['to']) {
                return $i;
            }
        }
        return $this->zones ? count($this->zones) - 1 : null;
    }

    private function format(float $v): string
    {
        return number_format($v, $v == floor($v) ? 0 : 1, ',', ' ');
    }

    private function rgba(GdImage $img, string $hex, int $alpha): int
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocatealpha($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }
}
