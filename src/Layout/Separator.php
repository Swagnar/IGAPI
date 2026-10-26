<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/** Pozioma linia na całą szerokość bounds, z odstępem nad i pod nią. */
final class Separator implements Component
{
    public function __construct(
        private readonly int $marginTop = 20,
        private readonly int $marginBottom = 20,
        private readonly string $color = '#334455',
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $y = $bounds->y + $this->marginTop;
        imageline($img, $bounds->x, $y, $bounds->x + $bounds->width, $y, GdImageHelper::hexToGdColor($img, $this->color));

        return $this->marginTop + 1 + $this->marginBottom;
    }
}
