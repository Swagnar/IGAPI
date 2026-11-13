<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Szkielet Story 1080×1920: tło (Theme), tytuł (+ opcjonalny podtytuł), obszar treści i stopka.
 *
 *   $slide = new Slide('MARS', footer: Slide::source('Źródło: NASA'));
 *   $slide->render($body);
 *   [$path, $url] = $slide->save('mars');
 *
 * Tytuł i stopka są rysowane dopiero w save() lub png(), więc zawsze leżą nad tym, co narysowano na canvasie
 * bezpośrednio (np. tło z kropek w PalestineModule).
 */
final class Slide
{
    private const TITLE_BASELINE    = 150;
    private const SUBTITLE_BASELINE = 200;
    private const SIDE_MARGIN       = 40;

    /** Wysokość treści slajdu z tytułem (bez podtytułu) i domyślną stopką 80 px — tyle dzieli VerticalSplit na ćwiartki */
    public const CONTENT_HEIGHT = HEIGHT - self::TITLE_BASELINE - 20 - 80;

    /** Szerokość treści slajdu */
    public const CONTENT_WIDTH = WIDTH - 2 * self::SIDE_MARGIN;

    private readonly GdImage $img;

    public function __construct(
        private readonly ?string $title = null,
        private readonly ?string $subtitle = null,
        private readonly ?Component $footer = null,
        private readonly int $footerHeight = 80,
    ) {
        $this->img = GdImageHelper::createCanvas(WIDTH, HEIGHT);
        Theme::background($this->img);
    }

    /** Domyślna, wycentrowana stopka ze źródłem danych. */
    public static function source(string $text, string $color = '#445577', int $size = 24): Component
    {
        return new Text($text, size: $size, color: $color, font: FONT, align: 'center', height: 50);
    }

    /** Canvas do rysowania niestandardowego (tła itp.) przed render(). */
    public function image(): GdImage
    {
        return $this->img;
    }

    /** Obszar między nagłówkiem a stopką. */
    public function bodyBounds(): Bounds
    {
        $top    = match (true) {
            $this->subtitle !== null => self::SUBTITLE_BASELINE + 20,
            $this->title !== null    => self::TITLE_BASELINE + 20,
            default                  => self::SIDE_MARGIN,
        };
        $bottom = HEIGHT - ($this->footer !== null ? $this->footerHeight : self::SIDE_MARGIN);

        return new Bounds(self::SIDE_MARGIN, $top, WIDTH - 2 * self::SIDE_MARGIN, $bottom - $top);
    }

    public function render(Component $body): static
    {
        $body->render($this->img, $this->bodyBounds());
        return $this;
    }

    /** @return array{0: string, 1: string} [ścieżka, URL] */
    public function save(string $name): array
    {
        $this->drawChrome();
        return GdImageHelper::saveImage($this->img, $name, PUBLIC_DIR, PUBLIC_URL);
    }

    /** Gotowy slajd jako bajty PNG, bez zapisu na dysk — dla podglądu w edytorze. */
    public function png(): string
    {
        $this->drawChrome();
        ob_start();
        imagepng($this->img, null, 1);
        imagedestroy($this->img);
        return (string) ob_get_clean();
    }

    private function drawChrome(): void
    {
        if ($this->title !== null) {
            // Długi tytuł (np. nazwa pluginu w podglądzie) zmniejszamy, aż zmieści się między marginesami
            $size = HEADER_SIZE;
            while ($size > 36 && GdImageHelper::textWidth($this->title, $size, FONT_BOLD) > imagesx($this->img) - 2 * self::SIDE_MARGIN) {
                $size -= 2;
            }
            GdImageHelper::centerText($this->img, $this->title, self::TITLE_BASELINE, $size, '#FFFFFF', FONT_BOLD);
        }
        if ($this->subtitle !== null) {
            GdImageHelper::centerText($this->img, $this->subtitle, self::SUBTITLE_BASELINE, 24, '#FFFFFF', FONT);
        }
        $this->footer?->render($this->img, new Bounds(
            x:      self::SIDE_MARGIN,
            y:      HEIGHT - $this->footerHeight,
            width:  WIDTH - 2 * self::SIDE_MARGIN,
            height: $this->footerHeight,
        ));
    }
}
