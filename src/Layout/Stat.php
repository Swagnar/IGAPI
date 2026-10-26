<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

/** Wycentrowana para: etykieta i pod nią duża wartość (np. "Łącznie zabici" / "74 544"). */
final class Stat implements Component
{
    public function __construct(
        private readonly string $label,
        private readonly string $value,
        private readonly int $labelSize = 34,
        private readonly int $valueSize = 64,
        private readonly string $labelColor = '#cfcfcf',
        private readonly string $valueColor = '#ffffff',
        private readonly int $gap = 4,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        return (new Stack(gap: $this->gap))
            ->add(new Text($this->label, size: $this->labelSize, color: $this->labelColor, font: FONT, align: 'center'))
            // wysokie cyfry potrzebują więcej miejsca niż domyślne size + 12
            ->add(new Text($this->value, size: $this->valueSize, color: $this->valueColor, font: FONT_BOLD, align: 'center', height: (int) ($this->valueSize * 1.3)))
            ->render($img, $bounds);
    }
}
