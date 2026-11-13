<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Wiersze tekstu z kolumnami wyrównanymi w pionie — szerokość kolumny = najszersza komórka.
 * Kolumna "flex" zabiera resztę miejsca (za długi tekst skracany wielokropkiem), więc kolejne kolumny
 * lądują przy prawej krawędzi.
 *
 *   new Table(
 *       rows:  [['cells' => ['1.', 'M6,4', 'Wyspy Salomona', '17 km'], 'color' => '#F87171'], …],
 *       align: ['right', 'left', 'left', 'right'],
 *       flex:  2,
 *       bold:  [1],
 *   )
 */
final class Table implements Component
{
    /**
     * @param list<array{cells: list<string>, color?: string}|list<string>> $rows
     * @param list<string> $align 'left' | 'right' dla każdej kolumny (domyślnie left)
     * @param list<int>    $bold  indeksy kolumn pisanych pogrubionym fontem
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $align = [],
        private readonly int $flex = -1,
        private readonly array $bold = [],
        private readonly int $size = 20,
        private readonly string $color = '#FFFFFF',
        private readonly ?string $font = null,
        private readonly int $rowHeight = 32,
        private readonly int $gap = 16,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $rows = array_map(fn($row) => isset($row['cells']) ? $row : ['cells' => array_values((array) $row)], $this->rows);
        if ($rows === []) {
            return 0;
        }

        $columns = max(array_map(fn($row) => count($row['cells']), $rows));
        $widths  = array_fill(0, $columns, 0);
        foreach ($rows as $row) {
            foreach ($row['cells'] as $i => $cell) {
                $widths[$i] = max($widths[$i], $this->textWidth((string) $cell, $i));
            }
        }

        if ($this->flex >= 0 && $this->flex < $columns) {
            $others              = array_sum($widths) - $widths[$this->flex] + $this->gap * ($columns - 1);
            $widths[$this->flex] = max(0, $bounds->width - $others);
        }

        $y = $bounds->y;
        foreach ($rows as $row) {
            $baseline = $y + $this->rowHeight - 8;
            $x        = $bounds->x;
            foreach ($row['cells'] as $i => $cell) {
                $text = $this->fit((string) $cell, $i, $widths[$i]);
                $tx   = ($this->align[$i] ?? 'left') === 'right' ? $x + $widths[$i] - $this->textWidth($text, $i) : $x;
                GdImageHelper::drawText($img, $text, $tx, $baseline, $this->size, $row['color'] ?? $this->color, $this->fontFor($i));
                $x += $widths[$i] + $this->gap;
            }
            $y += $this->rowHeight;
        }

        return $y - $bounds->y;
    }

    private function fontFor(int $column): string
    {
        return in_array($column, $this->bold, true) ? FONT_BOLD : ($this->font ?? FONT);
    }

    private function textWidth(string $text, int $column): int
    {
        if ($text === '') {
            return 0;
        }
        $box = imagettfbbox($this->size, 0, $this->fontFor($column), $text);
        return $box[2] - $box[0];
    }

    /** Skraca tekst z wielokropkiem, aż zmieści się w kolumnie. */
    private function fit(string $text, int $column, int $width): string
    {
        if ($this->textWidth($text, $column) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->textWidth($text . '…', $column) > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }
}
