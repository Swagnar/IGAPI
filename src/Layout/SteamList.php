<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Lista gier w stylu sklepu Steam: miniaturka, tytuł, studio z ceną, wykres dziennych szczytów,
 * szczyt graczy z doby i trend tydzień do tygodnia. Pokazuje tyle wierszy, ile zmieści się w bounds;
 * największy wzrost tygodnia wśród pokazanych dostaje wyróżnioną plakietkę.
 *
 *   { "type": "SteamList", "games": "{{polishGames.games}}" }
 *
 * Gra: appid, name, studio, peak24, daily (lista szczytów), trend (np. 0.12 = +12%), live, price, thumb.
 */
final class SteamList implements Component
{
    // Starsze gry mają miniaturkę pod stałym adresem; nowsze tylko pod adresem z hashem (pole thumb z appdetails)
    private const THUMB_URL = 'https://cdn.akamai.steamstatic.com/steam/apps/%d/capsule_231x87.jpg';

    // Wymiary wiersza listy (px)
    private const ROW_H   = 96;
    private const THUMB_H = 84;
    private const THUMB_W = 223;           // 231×87 przeskalowane do THUMB_H
    private const SPARK_W = 190;
    private const SPARK_H = 50;
    private const NUM_W   = 165;           // kolumna z liczbą graczy i trendem

    private const UP   = '#5BD48A';
    private const DOWN = '#F87171';

    public function __construct(
        private readonly array $games,
        private readonly int $rowGap = 10,
    ) {}

    public function render(GdImage $img, Bounds $b): int
    {
        $fits  = max(1, intdiv($b->height + $this->rowGap, self::ROW_H + $this->rowGap));
        $shown = array_slice($this->games, 0, $fits);
        if ($shown === []) {
            return 0;
        }
        $y = $b->y;

        // Największy wzrost tygodnia wśród pokazanych dostaje wyróżniony trend
        $trends = array_filter(array_column($shown, 'trend', 'appid'), fn($t) => $t !== null && $t >= 0.1);
        $hot    = $trends ? array_search(max($trends), $trends, true) : null;

        foreach ($shown as $game) {
            $this->row($img, $game, $b->x, $y, $b->width, $game['appid'] === $hot);
            $y += self::ROW_H + $this->rowGap;
        }
        return $y - $b->y - $this->rowGap;
    }

    private function row(GdImage $img, array $game, int $x, int $y, int $width, bool $hot = false): void
    {
        $right = $x + $width;

        // Tło wiersza: gradient od lewej, jak lista w sklepie Steam
        for ($i = 0; $i < $width; $i += 4) {
            $alpha = (int) (70 + 50 * $i / $width);
            imagefilledrectangle($img, $x + $i, $y, min($x + $i + 3, $right), $y + self::ROW_H, imagecolorallocatealpha($img, 40, 60, 110, $alpha));
        }

        $this->thumbnail($img, $game['thumb'] ?? sprintf(self::THUMB_URL, $game['appid']), $x + 6, $y + intdiv(self::ROW_H - self::THUMB_H, 2), $game['name']);

        // Tytuł i studio z ceną — do początku wykresu
        $textX    = $x + 6 + self::THUMB_W + 20;
        $sparkX   = $right - self::NUM_W - 20 - self::SPARK_W;
        $maxTitle = $sparkX - 20 - $textX;
        GdImageHelper::drawText($img, $this->fit($game['name'], 22, FONT_BOLD, $maxTitle), $textX, $y + 40, 22, '#FFFFFF', FONT_BOLD);
        $this->subline($img, $game, $textX, $y + 74, $maxTitle);

        if (count($game['daily']) >= 2) {
            $this->sparkline($img, $game['daily'], $sparkX, $y + intdiv(self::ROW_H - self::SPARK_H, 2), $game['trend'] ?? 0);
        }

        // Liczba graczy i trend
        GdImageHelper::rightText($img, number_format($game['peak24'], 0, ',', ' '), $right - 16, $y + 44, 26, '#FFFFFF', FONT_BOLD);
        [$trendText, $trendColor] = match (true) {
            $game['live']           => ['gra teraz', '#8899AA'],
            $game['trend'] === null => ['', '#8899AA'],
            default                 => [
                ($game['trend'] >= 0 ? '↑ ' : '↓ ') . number_format(abs($game['trend']) * 100, 0, ',', ' ') . '% tydz.',
                $game['trend'] >= 0 ? self::UP : self::DOWN,
            ],
        };
        if ($hot) {
            // Plakietka w kolorze wzrostu, ciemny tekst — „gra tygodnia” bez dodatkowego miejsca w wierszu
            $tw = GdImageHelper::textWidth($trendText, 15, FONT_BOLD);
            imagefilledrectangle($img, $right - 16 - $tw - 8, $y + 55, $right - 8, $y + 80, GdImageHelper::hexToGdColor($img, self::UP));
            GdImageHelper::rightText($img, $trendText, $right - 12, $y + 74, 15, '#0B1A12', FONT_BOLD);
        } elseif ($trendText !== '') {
            GdImageHelper::rightText($img, $trendText, $right - 16, $y + 74, 15, $trendColor, FONT);
        }
    }

    private function thumbnail(GdImage $img, string $url, int $x, int $y, string $name): void
    {
        $thumb = GdImageHelper::loadRemoteImage($url);
        if ($thumb === null) {
            imagefilledrectangle($img, $x, $y, $x + self::THUMB_W, $y + self::THUMB_H, imagecolorallocate($img, 31, 42, 72));
            $initials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', $name), 0, 2))));
            GdImageHelper::centerText($img, $initials, $y + intdiv(self::THUMB_H, 2) + 12, 28, '#8899CC', FONT_BOLD, $x + intdiv(self::THUMB_W, 2));
            return;
        }
        imagecopyresampled($img, $thumb, $x, $y, 0, 0, self::THUMB_W, self::THUMB_H, imagesx($thumb), imagesy($thumb));
    }

    /** „Techland · 149,99 zł”, przy promocji zielona plakietka „-40%” jak na Steamie. */
    private function subline(GdImage $img, array $game, int $x, int $y, int $maxWidth): void
    {
        $price = $game['price'] ?? null;
        $text  = $game['studio'];
        if (isset($price['free'])) {
            $text .= ' · Darmowa';
        } elseif ($price !== null && $price['discount'] === 0) {
            $text .= ' · ' . $price['final'];
        }
        GdImageHelper::drawText($img, $this->fit($text, 16, FONT, $maxWidth), $x, $y, 16, '#8C9AB8', FONT);

        if (($price['discount'] ?? 0) > 0) {
            $bx    = $x + GdImageHelper::textWidth($text, 16, FONT) + 12;
            $badge = "-{$price['discount']}%";
            $bw    = GdImageHelper::textWidth($badge, 15, FONT_BOLD) + 12;
            imagefilledrectangle($img, $bx, $y - 20, $bx + $bw, $y + 5, GdImageHelper::hexToGdColor($img, '#4C6B22'));
            GdImageHelper::drawText($img, $badge, $bx + 6, $y - 1, 15, '#BEEE11', FONT_BOLD);
            GdImageHelper::drawText($img, $price['final'], $bx + $bw + 8, $y, 16, '#BEEE11', FONT);
        }
    }

    /** Mały wykres dziennych szczytów: linia z wypełnieniem, kolor wg trendu tygodniowego. */
    private function sparkline(GdImage $img, array $values, int $x, int $y, float $trend): void
    {
        $n     = count($values);
        $min   = min($values) * 0.9;
        $range = (max($values) - $min) ?: 1;
        [$r, $g, $bl] = sscanf($trend >= 0 ? self::UP : self::DOWN, '#%02x%02x%02x');

        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = [
                $x + (int) round($i / ($n - 1) * self::SPARK_W),
                $y + self::SPARK_H - (int) round(($v - $min) / $range * self::SPARK_H),
            ];
        }

        $poly = [];
        foreach ($pts as [$px, $py]) {
            array_push($poly, $px, $py);
        }
        array_push($poly, $x + self::SPARK_W, $y + self::SPARK_H, $x, $y + self::SPARK_H);
        imagefilledpolygon($img, $poly, imagecolorallocatealpha($img, $r, $g, $bl, 105));

        $line = imagecolorallocate($img, $r, $g, $bl);
        imagesetthickness($img, 3);
        for ($i = 1; $i < $n; $i++) {
            imageline($img, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $line);
        }
        imagesetthickness($img, 1);
        [$lx, $ly] = end($pts);
        imagefilledellipse($img, $lx, $ly, 9, 9, $line);
    }

    /** Skraca tekst z wielokropkiem, żeby zmieścił się w szerokości. */
    private function fit(string $text, int $size, string $font, int $maxWidth): string
    {
        if (GdImageHelper::textWidth($text, $size, $font) <= $maxWidth) {
            return $text;
        }
        while (mb_strlen($text) > 1 && GdImageHelper::textWidth($text . '…', $size, $font) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }
}
