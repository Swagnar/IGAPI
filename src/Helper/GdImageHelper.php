<?php
namespace Swagnar\Igapi\Helper;

use GdImage;

class GdImageHelper {

    public const path = ROOT_DIR . '/debug';

    static public function centerText(GdImage $img, string $text, int $y, int $size, string $colorHex, string $font) {
        $rgb = sscanf($colorHex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];
        $x = (1080 - $width) / 2;
        imagettftext($img, $size, 0, (int)$x, $y, $col, $font, $text);
    }

    static public function drawText(GdImage $img, string $text, int $x, int $y, int $size, string $colorHex, string $font) {
        $rgb = sscanf($colorHex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $bounds = imagettftext($img, $size, 0, $x, $y, $col, $font, $text);
        return $bounds;
    }

    static public function debugPrint(GdImage $img, ?GdImage $mask = null) {
        if($mask === null) {
            $mask = imagecreatetruecolor(1,1);
        }

        $filename = 'debug-print-' . date('Ymd-His') . '.png';
        $path = rtrim(GdImageHelper::path, '/') . '/images/' . $filename;

        imagepng($img, $path, 6);
        imagedestroy($img);
        imagedestroy($mask);
    }

    static public function drawBackground(GdImage $img): void {
        for ($y = 0; $y < HEIGHT; $y++) {
            $t   = $y / HEIGHT;
            $r   = (int)(8  + (2  - 8)  * $t);
            $g   = (int)(12 + (4  - 12) * $t);
            $b   = (int)(32 + (8  - 32) * $t);
            $col = imagecolorallocate($img, max(0,$r), max(0,$g), max(0,$b));
            imageline($img, 0, $y, WIDTH, $y, $col);
        }
    }
}