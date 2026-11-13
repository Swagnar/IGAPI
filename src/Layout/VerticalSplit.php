<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

/**
 * Dzieli bounds na sloty ułożone pionowo (z odstępem między nimi) — każde dziecko dostaje jeden.
 * W przeciwieństwie do Stack wysokość slotu nie zależy od tego, ile zajmie poprzednie dziecko.
 *
 * Dziecko z rozmiarem (1–4 ćwiartki, np. „size” pluginu) dostaje stały slot: slot() — ten sam niezależnie
 * od sąsiadów; sloty idą od góry, a nieużyte ćwiartki zostają puste na dole. Dzieci bez rozmiaru dzielą
 * po równo to, co zostało (same dzieci bez rozmiaru = równy podział jak dawniej).
 * Zagnieżdżony podział (np. pula 3/4 na KOSMOSIE) dzieli się na tyle ćwiartek, ile sam zajmuje — quarters(3),
 * więc ćwiartka w środku ma tę samą wysokość co na całym slajdzie.
 */
final class VerticalSplit implements Component
{
    public const QUARTERS = 4;

    /** @var Component[] */
    private array $children = [];

    /** @var array<int, ?int> rozmiar dziecka w ćwiartkach (null = dzieli resztę) */
    private array $sizes = [];

    /** Na ile ćwiartek dzielą się bounds */
    private int $quarters = self::QUARTERS;

    public function __construct(private readonly int $gap = 0) {}

    public function add(Component $component, ?int $size = null): static
    {
        $this->children[] = $component;
        $this->sizes[]    = $size === null ? null : max(1, min(self::QUARTERS, $size));
        return $this;
    }

    public function quarters(int $quarters): static
    {
        $this->quarters = max(1, min(self::QUARTERS, $quarters));
        return $this;
    }

    /**
     * Wysokość slotu o rozmiarze $size ćwiartek w obszarze $height z odstępem $gap — ćwiartki z odstępami
     * między nimi, więc 2 + 2 i 1 + 3 wypełniają całość. Dla treści slajdu (1670 px, odstęp 40): 387 / 815 / 1242 / 1670.
     */
    public static function slot(int $size, int $height, int $gap, int $quarters = self::QUARTERS): int
    {
        $quarter = ($height - ($quarters - 1) * $gap) / $quarters;
        return (int) round($size * $quarter + ($size - 1) * $gap);
    }

    public function render(GdImage $img, Bounds $bounds): int
    {
        $count = count($this->children);
        if ($count === 0) {
            return 0;
        }

        $fixed   = 0;
        $unsized = 0;
        foreach ($this->sizes as $size) {
            $size === null ? $unsized++ : $fixed += self::slot($size, $bounds->height, $this->gap, $this->quarters);
        }
        $rest = $unsized > 0 ? intdiv(max(0, $bounds->height - $fixed - $this->gap * ($count - 1)), $unsized) : 0;

        $y = $bounds->y;
        foreach ($this->children as $i => $child) {
            $slotH = $this->sizes[$i] === null ? $rest : self::slot($this->sizes[$i], $bounds->height, $this->gap, $this->quarters);
            // Rozmiary ponad 4/4 (zła konfiguracja) — ostatnie dzieci dostają tylko to, co zostało
            $slotH = min($slotH, $bounds->bottom() - $y);
            if ($slotH <= 0) {
                break;
            }
            $child->render($img, new Bounds($bounds->x, $y, $bounds->width, $slotH));
            $y += $slotH + $this->gap;
        }

        return $bounds->height;
    }
}
