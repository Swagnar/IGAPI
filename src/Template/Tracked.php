<?php
namespace Swagnar\Igapi\Template;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Layout\Component;
use GdImage;

/**
 * Opakowanie komponentu, które zapisuje, gdzie faktycznie został narysowany — edytor używa tego,
 * żeby po kliknięciu w podgląd zaznaczyć właściwy węzeł.
 */
final class Tracked implements Component
{
    /** @param \ArrayObject<int, array{path: string, x: int, y: int, w: int, h: int}> $boxes */
    public function __construct(
        private readonly Component $inner,
        private readonly string $path,
        private readonly \ArrayObject $boxes,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $used = $this->inner->render($img, $bounds);
        $this->boxes[] = ['path' => $this->path, 'x' => $bounds->x, 'y' => $bounds->y, 'w' => $bounds->width, 'h' => $used];
        return $used;
    }
}
