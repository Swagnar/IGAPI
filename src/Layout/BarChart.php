<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Poziomy wykres słupkowy: [ikona] etykieta | słupek proporcjonalny do wartości | podpis wartości.
 * Wysokość wierszy dopasowuje się do bounds (maks. maxRowHeight).
 */
final class BarChart implements Component
{
    private const ICON_W = 32;
    private const ICON_H = 24;
    private const LABEL_SIZE = 22;
    private const LABEL_GAP  = 16;   // odstęp etykiety od początku słupków
    private const LABEL_MAX  = 0.45; // etykiety zajmują najwyżej tyle szerokości — dłuższe skracamy
    private const VALUE_SIZE = 15;
    private const VALUE_GAP  = 8;    // odstęp podpisu wartości od końca słupka

    /**
     * @param array<array{label: string, value: int|float, valueLabel: string, icon?: ?string, color?: ?string}> $rows
     *        posortowane malejąco; icon to opcjonalny URL obrazka (np. flagi), color wyróżnia słupek (#RRGGBB)
     */
    public function __construct(
        private readonly array $rows,
        private readonly int $maxRowHeight = 56,
        private readonly int $barHeight = 28,
        private readonly int $indent = 0,   // odsunięcie z obu stron
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        if (empty($this->rows)) {
            return 0;
        }

        $bounds = new Bounds($bounds->x + $this->indent, $bounds->y, $bounds->width - 2 * $this->indent, $bounds->height);

        $rowH      = min($this->maxRowHeight, intdiv($bounds->height, count($this->rows)));
        $iconW     = $this->hasIcons() ? self::ICON_W + 14 : 0;
        $labelW    = min($this->widestLabel(), (int) ($bounds->width * self::LABEL_MAX) - $iconW);
        $barStartX = $bounds->x + $iconW + $labelW + self::LABEL_GAP;
        $barMaxW   = $bounds->x + $bounds->width - $barStartX - self::VALUE_GAP - $this->widestValueLabel();
        $maxValue  = max(array_column($this->rows, 'value')) ?: 1;

        foreach ($this->rows as $i => $row) {
            $y = $bounds->y + $i * $rowH;

            if (!empty($row['icon']) && ($icon = GdImageHelper::loadRemoteImage($row['icon'])) !== null) {
                imagecopyresampled($img, $icon, $bounds->x, $y + 4, 0, 0, self::ICON_W, self::ICON_H, imagesx($icon), imagesy($icon));
                imagedestroy($icon);
            }

            GdImageHelper::drawText($img, $this->fit($row['label'], $labelW), $bounds->x + $iconW, $y + 26, self::LABEL_SIZE, '#CCDDFF', FONT);

            $barW   = max(2, (int)($row['value'] / $maxValue * $barMaxW));
            $barCol = !empty($row['color'])
                ? GdImageHelper::hexToGdColor($img, $row['color'])
                : imagecolorallocate($img, 40, min(255, 100 + $i * 8), max(0, 220 - $i * 6));   // lekko różnicowany odcień niebieskiego
            imagefilledrectangle($img, $barStartX, $y, $barStartX + $barW, $y + $this->barHeight, $barCol);

            GdImageHelper::drawText($img, $row['valueLabel'], $barStartX + $barW + self::VALUE_GAP, $y + 22, self::VALUE_SIZE, '#8899BB', FONT);
        }

        return count($this->rows) * $rowH;
    }

    private function hasIcons(): bool
    {
        foreach ($this->rows as $row) {
            if (!empty($row['icon'])) {
                return true;
            }
        }
        return false;
    }

    private function widestLabel(): int
    {
        return max(array_map(fn(array $row) => $this->labelWidth($row['label']), $this->rows));
    }

    private function labelWidth(string $text): int
    {
        $box = imagettfbbox(self::LABEL_SIZE, 0, FONT, $text);
        return $box[2] - $box[0];
    }

    /** Skraca etykietę z wielokropkiem, aż zmieści się w kolumnie. */
    private function fit(string $text, int $width): string
    {
        if ($this->labelWidth($text) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->labelWidth($text . '…') > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }

    /** Szerokość najdłuższego podpisu wartości — tyle miejsca zostawiamy za najdłuższym słupkiem. */
    private function widestValueLabel(): int
    {
        $widths = array_map(function (array $row): int {
            $box = imagettfbbox(self::VALUE_SIZE, 0, FONT, $row['valueLabel']);
            return $box[2] - $box[0];
        }, $this->rows);

        return max($widths);
    }
}
