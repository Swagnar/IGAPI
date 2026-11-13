<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Wykres kolumnowy wypełniający całe bounds: słupki od linii zera w górę lub w dół, każdy we własnym kolorze
 * (np. średnie roczne odchylenia temperatury w barwach „warming stripes”). Podpisane są słupki z `tick`
 * (np. okrągłe lata) — a jeśli żaden go nie ma, co `labelEvery` od końca; ostatni zawsze. Siatka Y w okrągłych krokach.
 *
 *   { "type": "ColumnChart", "bars": "{{climate.bars}}", "formatY": "{v}°C", "decimals": 1 }
 *
 * Słupek: label, value, color (opcjonalny — domyślnie `color`), tick (czy podpisać pod osią).
 */
final class ColumnChart implements Component
{
    private const PAD_L = 90;   // miejsce na etykiety Y po lewej
    private const PAD_R = 20;
    private const PAD_T = 20;
    private const PAD_B = 40;   // miejsce na etykiety X na dole

    /**
     * @param list<array{label: string, value: int|float, color?: string, tick?: bool}> $bars
     * @param string $formatY wzór etykiet osi Y, {v} to liczba po polsku ze znakiem, np. '{v}°C'
     */
    public function __construct(
        private readonly array $bars,
        private readonly string $formatY = '{v}',
        private readonly int $decimals = 0,
        private readonly int $labelEvery = 20,
        private readonly string $color = '#60A5FA',
        private readonly string $emptyText = 'Brak danych',
        private readonly string $labelColor = '#556688',
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $n = count($this->bars);
        if ($n === 0) {
            GdImageHelper::centerText($img, $this->emptyText, $bounds->y + 60, 30, $this->labelColor, FONT, $bounds->centerX());
            return 80;
        }

        $x0 = $bounds->x + self::PAD_L;
        $y0 = $bounds->y + self::PAD_T;
        $w  = $bounds->width  - self::PAD_L - self::PAD_R;
        $h  = $bounds->height - self::PAD_T - self::PAD_B;

        // Zakres osi Y zawsze obejmuje zero, krańce zaokrąglone do kroku siatki
        $values = array_map(fn($b) => (float) $b['value'], $this->bars);
        $step   = $this->step(max(max($values), 0) - min(min($values), 0));
        $minY   = floor(min(min($values), 0) / $step) * $step;
        $maxY   = ceil(max(max($values), 0) / $step) * $step;
        $range  = ($maxY - $minY) ?: 1;
        $yOf    = fn(float $v): int => (int) round($y0 + $h - ($v - $minY) / $range * $h);

        $grid = imagecolorallocatealpha($img, 100, 130, 200, 110);
        for ($v = $minY; $v <= $maxY + $step / 2; $v += $step) {
            $gy = $yOf($v);
            imageline($img, $x0, $gy, $x0 + $w, $gy, $grid);
            GdImageHelper::drawText($img, $this->labelY($v), $bounds->x + 8, $gy + 7, 18, $this->labelColor, FONT);
        }

        // Słupki z odstępem ~1/5 szerokości (min. 1 px), wyrównane do pełnych pikseli
        $slot = $w / $n;
        $gap  = $slot >= 5 ? max(1, (int) round($slot / 5)) : 0;
        $zero = $yOf(0);
        foreach ($this->bars as $i => $bar) {
            $bx1   = (int) round($x0 + $i * $slot);
            $bx2   = (int) round($x0 + ($i + 1) * $slot) - $gap - 1;
            $by    = $yOf((float) $bar['value']);
            $color = GdImageHelper::hexToGdColor($img, $bar['color'] ?? $this->color);
            imagefilledrectangle($img, $bx1, min($by, $zero), max($bx1, $bx2), max($by, $zero), $color);
        }
        imageline($img, $x0, $zero, $x0 + $w, $zero, imagecolorallocatealpha($img, 200, 210, 230, 60));

        // Etykiety X od ostatniego słupka wstecz, bez nachodzenia na siebie
        $ticks  = array_filter(array_column($this->bars, 'tick'));
        $bottom = $y0 + $h;
        $right  = PHP_INT_MAX;
        for ($i = $n - 1; $i >= 0; $i--) {
            $wanted = $i === $n - 1 || ($ticks ? !empty($this->bars[$i]['tick']) : ($n - 1 - $i) % max(1, $this->labelEvery) === 0);
            if (!$wanted) {
                continue;
            }
            $label = (string) $this->bars[$i]['label'];
            $tw    = GdImageHelper::textWidth($label, 20, FONT);
            $cx    = (int) round($x0 + ($i + 0.5) * $slot);
            $lx    = min(max($cx - intdiv($tw, 2), $x0), $x0 + $w - $tw);
            if ($lx + $tw + 16 > $right) {
                continue;
            }
            GdImageHelper::drawText($img, $label, $lx, $bottom + 28, 20, $this->labelColor, FONT);
            $right = $lx;
        }

        return $bounds->height;
    }

    /** Okrągły krok siatki tak, żeby wyszło 3–6 linii: 0,1 / 0,2 / 0,5 / 1 / 2 / 5 … */
    private function step(float $span): float
    {
        $span = $span ?: 1;
        $base = 10 ** floor(log10($span / 4));
        foreach ([1, 2, 5, 10] as $k) {
            if ($span / ($base * $k) <= 6) {
                return $base * $k;
            }
        }
        return $base * 10;
    }

    /** Plus przy dodatnich tylko na osi z wartościami ujemnymi (odchylenia) — przy samych liczbach „+5 000” razi. */
    private function labelY(float $value): string
    {
        $value  = round($value, $this->decimals);
        $text   = number_format(abs($value), $this->decimals, ',', ' ');
        $signed = min(array_column($this->bars, 'value') ?: [0]) < 0;
        return str_replace('{v}', ($value > 0 && $signed ? '+' : ($value < 0 ? '−' : '')) . $text, $this->formatY);
    }
}
