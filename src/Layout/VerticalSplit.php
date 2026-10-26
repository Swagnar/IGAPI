<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

/**
 * Dzieli bounds na równe, ułożone pionowo sloty (z odstępem między nimi) — każde dziecko dostaje jeden.
 * W przeciwieństwie do Stack wysokość slotu nie zależy od tego, ile zajmie poprzednie dziecko.
 */
final class VerticalSplit implements Component
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
        $count = count($this->children);
        if ($count === 0) {
            return 0;
        }

        $slotH = intdiv($bounds->height - $this->gap * ($count - 1), $count);

        foreach ($this->children as $i => $child) {
            $child->render($img, new Bounds(
                x:      $bounds->x,
                y:      $bounds->y + $i * ($slotH + $this->gap),
                width:  $bounds->width,
                height: $slotH,
            ));
        }

        return $bounds->height;
    }
}
