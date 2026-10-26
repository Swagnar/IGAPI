<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Helper\Console;
use GdImage;

/**
 * Zdjęcie pobierane z sieci, przeskalowane tak, by zmieściło się w bounds (i ewentualnie w maxHeight),
 * z opcjonalnym podpisem i opisem pod spodem. Jeśli zdjęcia nie da się pobrać — nic nie rysuje (0 px).
 */
final class Photo implements Component
{
    private const DESCRIPTION_LINES = 2;

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $caption = null,
        private readonly ?string $description = null,
        private readonly string $align = 'center',        // 'center' | 'left' — dotyczy zdjęcia i podpisów
        private readonly int $indent = 0,
        private readonly int $widthPadding = 0,
        private readonly ?int $maxHeight = null,          // null = cała dostępna wysokość
        private readonly int $minHeight = 150,            // mniej miejsca → pomijamy zdjęcie
        private readonly int $captionSize = 16,
        private readonly string $captionColor = '#AAAAAA',
        private readonly ?string $captionFont = null,     // null = FONT_BOLD
        private readonly int $descriptionSize = 16,
        private readonly string $descriptionColor = '#888888',
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        if ($this->url === null || $this->url === '') {
            return 0;
        }

        $x        = $bounds->x + $this->indent;
        $maxW     = $bounds->width - $this->widthPadding;
        $captionH = $this->captionReserve();
        $imgMaxH  = min($bounds->height, $this->maxHeight ?? PHP_INT_MAX) - $captionH;

        if ($imgMaxH < $this->minHeight) {
            Console::debug('Photo: za mało miejsca — pomijam rysowanie');
            return 0;
        }

        $photoImg = GdImageHelper::loadRemoteImage($this->url);
        if ($photoImg === null) {
            Console::error("Photo: nie udało się wczytać obrazka: {$this->url}");
            return 0;
        }

        $srcW = imagesx($photoImg);
        $srcH = imagesy($photoImg);

        $scale   = min($maxW / $srcW, $imgMaxH / $srcH);
        $drawW   = (int) ($srcW * $scale);
        $drawH   = (int) ($srcH * $scale);
        $offsetX = $this->align === 'left' ? $x : $x + (int) (($maxW - $drawW) / 2);

        imagecopyresampled($img, $photoImg, $offsetX, $bounds->y, 0, 0, $drawW, $drawH, $srcW, $srcH);
        imagedestroy($photoImg);

        $captionBounds = new Bounds($x, $bounds->y + $drawH + 6, $maxW, max(0, $captionH));
        $usedCaption   = $this->captions()->render($img, $captionBounds);

        Console::success('Photo: zdjęcie narysowane pomyślnie');

        return $drawH + 6 + $usedCaption;
    }

    private function captions(): Stack
    {
        $font  = $this->captionFont ?? FONT_BOLD;
        $stack = new Stack();

        if (!empty($this->caption)) {
            $stack->add($this->align === 'center'
                ? new Text($this->caption, size: $this->captionSize, color: $this->captionColor, font: $font, align: 'center')
                : new WrappedText($this->caption, size: $this->captionSize, color: $this->captionColor, font: $font, lineHeight: $this->captionSize + 8));
        }

        if (!empty($this->description)) {
            $stack->add(new WrappedText(
                $this->description,
                size: $this->descriptionSize, color: $this->descriptionColor, font: FONT, lineHeight: $this->descriptionSize + 6,
            ));
        }

        return $stack;
    }

    /** Ile miejsca zostawić pod zdjęciem na podpis i opis (zanim zmierzymy realny tekst). */
    private function captionReserve(): int
    {
        $reserve = 0;
        if (!empty($this->caption)) {
            $lines    = $this->align === 'center' ? 1 : 2;
            $reserve += $lines * ($this->captionSize + 8) + 10;
        }
        if (!empty($this->description)) {
            $reserve += self::DESCRIPTION_LINES * ($this->descriptionSize + 6) + 10;
        }
        return $reserve;
    }
}
