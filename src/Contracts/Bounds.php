<?php

namespace Swagnar\Igapi\Contracts;

final class Bounds
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public function centerX(): int { return $this->x + intdiv($this->width, 2); }
    public function bottom(): int  { return $this->y + $this->height; }
}