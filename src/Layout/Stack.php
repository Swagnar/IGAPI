<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

final class Stack implements Component
{
    /** @var Component[] */
    private array $children = [];

    public function __construct(private readonly int $gap = 0) {}

    public function add(Component $component): static
    {
        $this->children[] = $component;
        return $this;
    }

    public function render(GdImage $img, Bounds $bounds): int
    {
        $y = $bounds->y;

        foreach ($this->children as $i => $child) {
            $childBounds = new Bounds(
                x:      $bounds->x,
                y:      $y,
                width:  $bounds->width,
                height: max(0, $bounds->y + $bounds->height - $y),
            );

            $used = $child->render($img, $childBounds);
            $y += $used;

            if ($i < count($this->children) - 1) {
                $y += $this->gap;
            }
        }

        return $y - $bounds->y;
    }
}