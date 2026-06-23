<?php
namespace Swagnar\Igapi\Helper;

use GdImage;

class GdImageHelper {
    static public function centerText(GdImage $img, string $text, int $y, int $size, string $colorHex, string $font) {
        $rgb = sscanf($colorHex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];
        $x = (1080 - $width) / 2;
        imagettftext($img, $size, 0, (int)$x, $y, $col, $font, $text);
    }
}