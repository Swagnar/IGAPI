<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Jak blisko Ziemi przeleciała asteroida — oś liniowa w odległościach Księżyca (LD) od Ziemi, od zera do
 * „okrągłej” wartości tuż za asteroidą. Proporcje są prawdziwe: przy przelocie 69× dalej niż Księżyc
 * Księżyc stoi tuż przy Ziemi, a przy przelocie bliżej niż Księżyc oś kończy się zaraz za nim.
 * Podziałki co okrągły krok (10×, 20×…), Księżyc zawsze podpisany, satelity GEO — gdy jest dla nich miejsce.
 */
final class MoonBar implements Component
{
    private const GEO_LD   = 35786 / 384400;
    private const NICE     = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8];   // końce osi: te liczby × 10^n
    private const AXIS_Y   = 58;  // oś względem góry komponentu
    private const HEIGHT   = 112;

    public function __construct(
        private readonly float $moonPercent,
        private readonly int $indent = 0,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $ld     = max(0.0, $this->moonPercent / 100);
        $maxLd  = $this->axisEnd(max($ld * 1.08, 1.15));
        $x      = $bounds->x + $this->indent;
        $width  = $bounds->width - 2 * $this->indent;
        $axisY  = $bounds->y + self::AXIS_Y;
        $icon   = 34;
        $ax0    = $x + $icon + 10;
        $ax1    = $x + $width - 14;
        $pos    = fn(float $v) => (int) round($ax0 + $v / $maxLd * ($ax1 - $ax0));
        $color  = match (true) {
            $ld < 1  => '#F87171',
            $ld < 10 => '#FBBF24',
            default  => '#4FD1C5',
        };
        [$r, $g, $b] = sscanf(ltrim($color, '#'), '%02x%02x%02x');

        // Ziemia na początku osi
        GdImageHelper::drawEmojiIcon($img, '1f30d', $x, $axisY - intdiv($icon, 2), $icon);

        // Oś: tło, potem odcinek od Ziemi do asteroidy w jej kolorze
        $track = GdImageHelper::hexToGdColor($img, '#1E2A3A');
        $this->thickLine($img, $ax0, $ax1, $axisY, 8, $track);
        $mx = $pos($ld);
        $this->thickLine($img, $ax0, $mx, $axisY, 8, imagecolorallocatealpha($img, $r, $g, $b, 40));

        // Punkty odniesienia pod osią: najpierw Księżyc (zawsze), potem GEO i okrągłe wielokrotności —
        // podpis, który nachodziłby na już narysowany, jest pomijany (sama kreska zostaje)
        $refs = [[1, 'Księżyc'], [self::GEO_LD, 'satelity GEO']];
        $step = $this->tickStep($maxLd);
        for ($v = $step; $v <= $maxLd + 1e-9; $v += $step) {
            // Ułamki poniżej Księżyca bez podpisu — „0,2×” tylko zaciemnia; tam mówią GEO i Księżyc
            if (abs($v - 1) > 1e-9) {
                $refs[] = [$v, $v < 1 ? null : number_format($v, floor($v) != $v ? 1 : 0, ',', ' ') . '×'];
            }
        }
        $taken = [[$x, $x + $icon]];   // pod ikoną Ziemi nic nie piszemy — tam jest „0”
        $tick  = GdImageHelper::hexToGdColor($img, '#3A4A66');
        foreach ($refs as [$value, $label]) {
            $tx = $pos($value);
            if ($tx - $ax0 < 6 && $value !== 1) {
                continue;   // zlewa się z Ziemią
            }
            imagefilledrectangle($img, $tx - 1, $axisY - 9, $tx + 1, $axisY + 9, $tick);
            if ($label === null) {
                continue;
            }
            $w  = $this->textWidth($label, 14);
            $lx = max($x, min($x + $width - $w, $tx - intdiv($w, 2)));
            if ($value === 1) {
                $lx = max($lx, $x + $icon + 12);   // Księżyc przy samej Ziemi: podpis tuż za jej ikoną
            }
            $free = true;
            foreach ($taken as [$a, $bEnd]) {
                $free = $free && ($lx + $w + 10 < $a || $lx > $bEnd + 10);
            }
            if ($free) {
                GdImageHelper::drawText($img, $label, $lx, $axisY + 32, 14, '#8894A8', FONT);
                $taken[] = [$lx, $lx + $w];
            }
        }
        GdImageHelper::drawEmojiIcon($img, '1f315', $pos(1) - 9, $axisY - 9, 18);

        // Asteroida: poświata, kropka, kreska do podpisu nad osią
        for ($step = 0; $step < 6; $step++) {
            $d = 44 - $step * 5;
            imagefilledellipse($img, $mx, $axisY, $d, $d, imagecolorallocatealpha($img, $r, $g, $b, 118));
        }
        imagefilledellipse($img, $mx, $axisY, 16, 16, GdImageHelper::hexToGdColor($img, $color));
        imageellipse($img, $mx, $axisY, 16, 16, GdImageHelper::hexToGdColor($img, '#FFFFFF'));
        imageline($img, $mx, $axisY - 12, $mx, $bounds->y + 26, imagecolorallocatealpha($img, $r, $g, $b, 30));

        $real = $this->moonPercent / 100;
        $text = $real < 1
            ? 'bliżej niż Księżyc — ' . number_format($this->moonPercent, $this->moonPercent < 1 ? 1 : 0, ',', ' ') . '% jego odległości'
            : number_format($real, $real < 10 ? 1 : 0, ',', ' ') . '× dalej niż Księżyc';
        $w  = $this->textWidth($text, 18, FONT_BOLD);
        $lx = max($x, min($x + $width - $w, $mx - intdiv($w, 2)));
        GdImageHelper::drawText($img, $text, $lx, $bounds->y + 20, 18, $color, FONT_BOLD);

        return self::HEIGHT;
    }

    /** Najmniejszy „okrągły” koniec osi ≥ $v: 1,2 · 1,5 · 2 · … · 8 · 10 · 12 · 15 … */
    private function axisEnd(float $v): float
    {
        $base = 10 ** floor(log10($v));
        foreach ([...self::NICE, 10] as $k) {
            if ($base * $k >= $v - 1e-9) {
                return $base * $k;
            }
        }
        return $base * 10;
    }

    /** Krok podziałek tak, żeby wyszło ich 3–8: 0,1 · 0,2 · 0,5 · 1 · 2 · 5 · 10 … */
    private function tickStep(float $max): float
    {
        $base = 10 ** floor(log10($max / 4));
        foreach ([1, 2, 5, 10] as $k) {
            if ($max / ($base * $k) <= 8) {
                return $base * $k;
            }
        }
        return $base * 10;
    }

    /** Pozioma linia o zaokrąglonych końcach. */
    private function thickLine(GdImage $img, int $x1, int $x2, int $y, int $thickness, int $color): void
    {
        if ($x2 <= $x1) {
            return;
        }
        $half = intdiv($thickness, 2);
        imagefilledrectangle($img, $x1, $y - $half, $x2, $y + $half - 1, $color);
        imagefilledellipse($img, $x1, $y, $thickness, $thickness, $color);
        imagefilledellipse($img, $x2, $y, $thickness, $thickness, $color);
    }

    private function textWidth(string $text, int $size, ?string $font = null): int
    {
        $box = imagettfbbox($size, 0, $font ?? FONT, $text);
        return $box[2] - $box[0];
    }
}
