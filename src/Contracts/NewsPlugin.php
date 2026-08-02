<?php

namespace Swagnar\Igapi\Contracts;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

interface NewsPlugin 
{
    public function getLabel(): string;

    public function fetchData(): array;

    public function draw(GdImage $img, array $data, Bounds $bounds): void;
}