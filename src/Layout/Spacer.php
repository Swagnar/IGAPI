<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

final class Spacer implements Component
{
    public function __construct(private readonly int $height) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        return $this->height;
    }
}