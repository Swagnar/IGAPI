<?php
namespace Swagnar\Igapi\Contracts;

use Swagnar\Igapi\Contracts\NewsPlugin;
use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Layout\Component;
use GdImage;

abstract class AbstractPlugin implements NewsPlugin
{
    abstract public function getLabel(): string;

    abstract public function fetchData(): array;

    abstract protected function content(array $data): Component;

    final public function draw(GdImage $img, array $data, Bounds $bounds): void
    {
        $this->content($data)->render($img, $bounds);
    }
}