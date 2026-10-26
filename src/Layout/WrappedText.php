<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;


final class WrappedText implements Component
{
    public function __construct(
        private readonly string $text,
        private readonly int $size,
        private readonly string $color,
        private readonly string $font,
        private readonly int $indent = 0,
        private readonly int $widthPadding = 0,
        private readonly int $lineHeight = 24,
        private readonly int $marginBottom = 0,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        if (trim($this->text) === '') {
            return 0;
        }

        $x        = $bounds->x + $this->indent;
        $maxWidth = $bounds->width - $this->widthPadding;

        // drawWrappedText przyjmuje linię bazową pierwszego wiersza — przesuwamy ją o wysokość liter,
        // żeby tekst zaczynał się w obrębie bounds, a nie nad nim
        $firstBaseline = $bounds->y + $this->size + 4;

        $endY = GdImageHelper::drawWrappedText(
            $img, $this->text, $x, $firstBaseline, $maxWidth,
            $this->size, $this->color, $this->font, $this->lineHeight
        );

        // $endY to linia bazowa "następnego" wiersza → zajęta wysokość = liczba wierszy * lineHeight (+ margines)
        return ($endY - $firstBaseline) + 4 + $this->marginBottom;
    }
}
