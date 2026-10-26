<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

final class Text implements Component
{
    public function __construct(
        private readonly string $text,
        private readonly int $size,
        private readonly string $color,
        private readonly string $font,
        private readonly string $align = 'left', // 'left' | 'center' | 'right'
        private readonly int $indent = 0,        // odsunięcie od krawędzi (lewej przy left, prawej przy right)
        private readonly int $height = 0,        // 0 = auto (size + 12)
        private readonly int $marginTop = 0,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $rowHeight = $this->height > 0 ? $this->height : $this->size + 12;
        $baseline  = $bounds->y + $this->marginTop + $rowHeight - 6;

        match ($this->align) {
            'center' => GdImageHelper::centerText($img, $this->text, $baseline, $this->size, $this->color, $this->font, $bounds->centerX()),
            'right'  => GdImageHelper::rightText($img, $this->text, $bounds->x + $bounds->width - $this->indent, $baseline, $this->size, $this->color, $this->font),
            default  => GdImageHelper::drawText($img, $this->text, $bounds->x + $this->indent, $baseline, $this->size, $this->color, $this->font),
        };

        return $this->marginTop + $rowHeight;
    }
}
