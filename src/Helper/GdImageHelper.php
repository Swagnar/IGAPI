<?php
namespace Swagnar\Igapi\Helper;

use Swagnar\Igapi\Contracts\Bounds;

use GdImage;

class GdImageHelper {

    public const path = ROOT_DIR . '/debug';

    static public function centerText(
        GdImage $img, 
        string $text, 
        int $y, 
        int $size, 
        string $colorHex, 
        string $font
    ):void {
        $rgb = sscanf($colorHex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];
        $x = (1080 - $width) / 2;
        imagettftext($img, $size, 0, (int)$x, $y, $col, $font, $text);
    }

    static public function drawText(
        GdImage $img, 
        string $text, 
        int $x, 
        int $y, 
        int $size, 
        string $colorHex, 
        string $font
    ):array {
        $rgb = sscanf($colorHex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $bounds = imagettftext($img, $size, 0, $x, $y, $col, $font, $text);
        return $bounds;
    }

    static public function debugPrint(
        GdImage $img, 
        ?GdImage $mask = null
    ):void {
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

    public static function saveImage(GdImage $img, string $name, string $dir, string $baseUrl): array
    {
        $filename = $name . '-' . date('Ymd-His') . '.png';
        $path     = rtrim($dir,     '/') . '/' . $filename;
        $url      = rtrim($baseUrl, '/') . '/' . $filename;

        imagepng($img, $path, 6);
        imagedestroy($img);

        return [$path, $url];
    }

    static public function createCanvas(int $width, int $height): GdImage
    {
        $img = imagecreatetruecolor(WIDTH, HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        GdImageHelper::drawBackground($img);
        return $img;
    }

    public static function drawWrappedText(
        GdImage $img,
        string $text,
        int $x,
        int $y,
        int $maxWidth,
        int $fontSize,
        string $color,
        string $font,
        int $lineHeight
    ): int {
        $words = explode(' ', $text);
        $line = '';
        $currentY = $y;

        foreach ($words as $word) {
            $testLine = $line === '' ? $word : $line . ' ' . $word;
            $bbox = imagettfbbox($fontSize, 0, $font, $testLine);
            $lineWidth = $bbox[2] - $bbox[0];

            if ($lineWidth > $maxWidth && $line !== '') {
                self::drawText($img, $line, $x, $currentY, $fontSize, $color, $font);
                $currentY += $lineHeight;
                $line = $word;
            } else {
                $line = $testLine;
            }
        }

        if ($line !== '') {
            self::drawText($img, $line, $x, $currentY, $fontSize, $color, $font);
            $currentY += $lineHeight;
        }

        return $currentY;
    }

    public static function drawProgressBar(
        GdImage $img,
        int $x,
        int $y,
        int $width,
        int $height,
        float $percent,
        string $fillColorHex,
        string $bgColorHex
    ): void {
        $bg = self::hexToGdColor($img, $bgColorHex);
        $fill = self::hexToGdColor($img, $fillColorHex);

        imagefilledrectangle($img, $x, $y, $x + $width, $y + $height, $bg);

        $clampedPercent = min(max($percent, 0), 100);
        $fillWidth = (int) round($width * ($clampedPercent / 100));

        if ($fillWidth > 0) {
            imagefilledrectangle($img, $x, $y, $x + $fillWidth, $y + $height, $fill);
        }
    }

    public static function hexToGdColor(GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return imagecolorallocate($img, $r, $g, $b);
    }

    public static function drawEmojiIcon(GdImage $img, string $codepoint, int $x, int $y, int $size): void
    {
        $iconUrl = "https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/72x72/{$codepoint}.png";

        try {
            $iconData = @file_get_contents($iconUrl);
            if ($iconData === false) {
                return;
            }

            $iconImg = @imagecreatefromstring($iconData);
            if ($iconImg === false) {
                return;
            }

            // Twemoji ma przezroczyste tło i jest 72x72 - skalujemy do żądanego rozmiaru
            imagecopyresampled($img, $iconImg, $x, $y, 0, 0, $size, $size, 72, 72, $iconImg->width ?? 72, $iconImg->height ?? 72);
            imagedestroy($iconImg);
        } catch (\Throwable) {
            // ikona niedostępna — kontynuuj bez niej
        }
    }

    public static function drawFrame(GdImage $img, Bounds $bounds, string $label, string $borderColor = '#334455', string $labelColor = '#AABBCC'): void
    {
        $rgb = sscanf($borderColor, "#%02x%02x%02x");
        $border = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);

        // Ramka wokół obszaru pluginu
        imagerectangle(
            $img,
            $bounds->x,
            $bounds->y,
            $bounds->x + $bounds->width,
            $bounds->y + $bounds->height,
            $border
        );

        // Podpis w lewym górnym rogu
        self::drawText(
            $img,
            $label,
            $bounds->x + 8,
            $bounds->y + 18,
            12,
            $labelColor,
            FONT
        );
    }

    public static function translateToPolish(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        Console::info("Tłumaczę tekst na polski: $text");
        try {
            $url = 'https://translate.googleapis.com/translate_a/single?' . http_build_query([
                'client' => 'gtx',
                'sl'     => 'en',
                'tl'     => 'pl',
                'dt'     => 't',
                'q'      => $text,
            ]);

            $context = stream_context_create([
                'http' => [
                    'header'  => "User-Agent: Mozilla/5.0\r\n",
                    'timeout' => 5,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return $text;
            }

            $decoded = json_decode($response, true);
            $translated = $decoded[0][0][0] ?? null;
            Console::success("Tłumaczenie zakończone: $translated");
            return is_string($translated) && $translated !== '' ? $translated : $text;
        } catch (\Throwable $e) {
            Console::error("Tłumaczenie nieudane: " . $e->getMessage());
            return $text;
        }
    }

    public static function truncateAtWordBoundary(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '…';
    }

}