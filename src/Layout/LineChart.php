<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Wykres liniowy wypełniający całe bounds. Oś X jest proporcjonalna do wartości x (np. timestampów),
 * więc przerwy w danych są widoczne. Kolor zależy od kierunku: zielony gdy rośnie, czerwony gdy spada.
 */
final class LineChart implements Component
{
    private const PAD_L = 90;   // miejsce na etykiety Y po lewej
    private const PAD_R = 20;
    private const PAD_T = 20;
    private const PAD_B = 40;   // miejsce na etykiety X na dole

    /**
     * @param array<array{x: int|float, y: int|float}> $points posortowane rosnąco po x
     * @param callable(float): string $formatY
     * @param callable(int|float): string $formatX
     */
    public function __construct(
        private readonly array $points,
        private readonly mixed $formatY,
        private readonly mixed $formatX,
        private readonly string $emptyText = 'Brak danych',
        private readonly string $labelColor = '#556688',
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $n = count($this->points);
        if ($n < 2) {
            GdImageHelper::centerText($img, $this->emptyText, $bounds->y + 60, 30, $this->labelColor, FONT, $bounds->centerX());
            return 80;
        }

        $x0 = $bounds->x + self::PAD_L;
        $y0 = $bounds->y + self::PAD_T;
        $w  = $bounds->width  - self::PAD_L - self::PAD_R;
        $h  = $bounds->height - self::PAD_T - self::PAD_B;

        $values = array_column($this->points, 'y');
        $minY   = min($values);
        $rangeY = (max($values) - $minY) ?: 1;

        $firstX = $this->points[0]['x'];
        $rangeX = ($this->points[$n - 1]['x'] - $firstX) ?: 1;

        // Siatka pozioma (4 przedziały) z etykietami wartości
        $gridColor = imagecolorallocatealpha($img, 100, 130, 200, 110);
        for ($gi = 0; $gi <= 4; $gi++) {
            $gy = (int)($y0 + $h - $gi / 4 * $h);
            imageline($img, $x0, $gy, $x0 + $w, $gy, $gridColor);
            GdImageHelper::drawText($img, ($this->formatY)($minY + $gi / 4 * $rangeY), $bounds->x + 8, $gy - 10, 18, $this->labelColor, FONT);
        }

        $isUp = $this->points[$n - 1]['y'] >= $this->points[0]['y'];
        [$r, $g, $b] = $isUp ? [52, 211, 153] : [248, 113, 113];

        $pts = array_map(fn($p) => [
            (int)($x0 + ($p['x'] - $firstX) / $rangeX * $w),
            (int)($y0 + $h - ($p['y'] - $minY) / $rangeY * $h),
        ], $this->points);

        // Zanikające pionowe "wypełnienie" pod każdym punktem
        $bottom = $y0 + $h;
        foreach ($pts as [$px, $py]) {
            for ($fy = $py; $fy <= $bottom; $fy++) {
                $alpha = (int)(100 + 27 * ($fy - $py) / max(1, $bottom - $py));
                imagesetpixel($img, $px, $fy, imagecolorallocatealpha($img, $r, $g, $b, $alpha));
            }
        }

        $lineColor = imagecolorallocate($img, $r, $g, $b);
        imagesetthickness($img, 3);
        for ($i = 0; $i < $n - 1; $i++) {
            imageline($img, $pts[$i][0], $pts[$i][1], $pts[$i + 1][0], $pts[$i + 1][1], $lineColor);
        }
        imagesetthickness($img, 1);

        // Wyróżniony ostatni punkt
        [$lx, $ly] = $pts[$n - 1];
        imagefilledellipse($img, $lx, $ly, 14, 14, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, $lx, $ly, 8, 8, $lineColor);

        GdImageHelper::drawText($img, ($this->formatX)($firstX), $x0, $bottom + 28, 20, $this->labelColor, FONT);
        GdImageHelper::rightText($img, ($this->formatX)($this->points[$n - 1]['x']), $x0 + $w, $bottom + 28, 20, $this->labelColor, FONT);

        return $bounds->height;
    }
}
