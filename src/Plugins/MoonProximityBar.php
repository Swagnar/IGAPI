<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

final class MoonProximityBar implements Component
{
    public function __construct(
        private readonly float $moonPercent,
        private readonly int $indent = 60,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $x        = $bounds->x + $this->indent;
        $barWidth = $bounds->width - $this->indent * 2;
        $iconSize = 24;
        $barColor = $this->moonPercent <= 100 ? '#4FD1C5' : '#F87171';

        if ($this->moonPercent <= 300) {
            $scaleMax    = 300;
            $fillPercent = min($this->moonPercent, $scaleMax) / $scaleMax * 100;
            $moonMarkerX = (int) round($x + $barWidth * (100 / $scaleMax));

            GdImageHelper::drawProgressBar($img, $x, $bounds->y, $barWidth, 22, $fillPercent, $barColor, '#1E2A3A');
            imagefilledrectangle($img, $moonMarkerX, $bounds->y - 4, $moonMarkerX + 3, $bounds->y + 26, GdImageHelper::hexToGdColor($img, '#FFFFFF'));

            GdImageHelper::drawEmojiIcon($img, '1f30d', $x, $bounds->y + 32, $iconSize);
            GdImageHelper::drawEmojiIcon($img, '1f315', $moonMarkerX - $iconSize + 15, $bounds->y + 32, $iconSize);

            GdImageHelper::drawText($img, 'Ziemia',  $x + $iconSize + 8, $bounds->y + 50, 16, '#8894A8', FONT);
            GdImageHelper::drawText($img, 'Księżyc', $moonMarkerX + 20,  $bounds->y + 50, 16, '#8894A8', FONT);

            return 90;
        }

        $multiplier = number_format($this->moonPercent / 100, 1, ',', ' ');
        GdImageHelper::drawText(
            $img,
            "Na szczęście to aż {$multiplier}× dalej niż Księżyc",
            $x, $bounds->y + 40, 20, '#F87171', FONT_BOLD
        );

        return 70;
    }
}