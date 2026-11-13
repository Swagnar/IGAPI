<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

/**
 * Dzieci obok siebie — szerokości proporcjonalne do wag (domyślnie równe), z odstępem między kolumnami.
 * Zajęta wysokość = najwyższa kolumna.
 *
 *   (new Columns(weights: [3, 2], gap: 30))->add($mapa)->add($ranking)
 */
final class Columns implements Component
{
    /** @var Component[] */
    private array $children = [];

    /** @param list<float> $weights */
    public function __construct(
        private readonly array $weights = [],
        private readonly int $gap = 24,
    ) {}

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

        $weights = array_map(fn($i) => (float) ($this->weights[$i] ?? 1), range(0, $count - 1));
        $free    = $bounds->width - $this->gap * ($count - 1);
        $total   = array_sum($weights) ?: 1;

        $x    = $bounds->x;
        $used = 0;
        foreach ($this->children as $i => $child) {
            $width = (int) round($free * $weights[$i] / $total);
            $used  = max($used, $child->render($img, new Bounds($x, $bounds->y, $width, $bounds->height)));
            $x    += $width + $this->gap;
        }
        return $used;
    }
}
