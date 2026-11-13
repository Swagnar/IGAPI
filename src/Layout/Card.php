<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

/**
 * Szklana karta (Theme) pod blokiem: wypełnia cały slot (plugin 3/4 ma kartę 3/4, niezależnie od treści), a treść
 * leży na niej z odstępem od krawędzi. Blok, który nic nie narysował, nie dostaje karty — to sprawdza render
 * na roboczym płótnie. ComponentFactory owija tak każdy plugin wstawiony wprost w slajd.
 */
final class Card implements Component
{
    public function __construct(private readonly Component $inner) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        [$side, $top, $bottom] = Theme::PADDING;
        $content = new Bounds($bounds->x + $side, $bounds->y + $top, $bounds->width - 2 * $side, $bounds->height - $top - $bottom);

        $scratch = imagecreatetruecolor(imagesx($img), imagesy($img));
        $used    = $this->inner->render($scratch, $content);
        imagedestroy($scratch);
        if ($used <= 0) {
            return 0;
        }

        Theme::card($img, $bounds);
        $this->inner->render($img, $content);
        return $bounds->height;
    }
}
