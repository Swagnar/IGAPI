<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

interface Component
{
    public function render(GdImage $img, Bounds $bounds): int;
}