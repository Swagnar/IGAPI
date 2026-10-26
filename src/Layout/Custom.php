<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

/**
 * Furtka na rysowanie, którego nie da się sensownie złożyć z innych komponentów (np. mapa pogody).
 * Callback dostaje canvas i bounds, a zwraca zajętą wysokość.
 */
final class Custom implements Component
{
    /** @param callable(GdImage, Bounds): int $draw */
    public function __construct(private readonly mixed $draw) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        return ($this->draw)($img, $bounds);
    }
}
