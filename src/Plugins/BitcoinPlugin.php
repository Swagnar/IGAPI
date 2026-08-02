<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\NewsPlugin;
use Swagnar\Igapi\Contracts\Bounds;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use GdImage;

class BitcoinPlugin implements NewsPlugin 
{
    private const BTC_FILE  = ROOT_DIR . '/btc_data.txt';

    public function __construct(
        private readonly JsonClient $http,
    )
    {
        Console::boot("BitcoinPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Bitcoin'; }

    public function fetchData(): array {
        Console::plugin("BitcoinPlugin: pobieram dane...");
        $dataUrl  = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd&include_24hr_change=true';
        $response = $this->http->getCurl($dataUrl);
        $data     = $response['data'];

        $price = $data['bitcoin']['usd'] ?? null;

        if ($price !== null) {
            $this->appendIfNeeded((float)$price);
        }

        return [
            'price'  => $price,
            'change' => $data['bitcoin']['usd_24h_change'] ?? null,
            'history' => $this->loadHistory(),
        ];
    }

    /**
     * Dopisuje nową cenę do pliku historii — o ile nie zapisano już wpisu w tej samej minucie
     * i o ile cena nie wygląda na błędny odczyt (nagły, nierealny skok względem ostatniej wartości).
     */
    private function appendIfNeeded(float $price): void
    {
        $file = self::BTC_FILE;

        if ($price <= 0) {
            Console::error("BitcoinPlugin: odrzucono nieprawidłową cenę ($price)");
            return;
        }

        $lastLine = '';
        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false && count($lines) > 0) {
                $lastLine = trim(end($lines));
            }
        }

        // Nie dopisuj, jeśli ostatni zapis jest z tej samej minuty
        $lastTimestamp = substr($lastLine, 0, 16); // "2026-06-25 20:26"
        $nowTimestamp  = date('Y-m-d H:i');
        if ($lastTimestamp === $nowTimestamp) {
            Console::debug("BitcoinPlugin: wpis z tej minuty już istnieje, pomijam.");
            return;
        }

        // Ochrona przed błędnymi odczytami API — zbyt duże odchylenie od ostatniej ceny
        if ($lastLine !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} ([\d.]+)$/', $lastLine, $m)) {
            $lastPrice = (float)$m[1];
            if ($lastPrice > 0) {
                $deltaPct = abs($price - $lastPrice) / $lastPrice * 100;
                if ($deltaPct > 20) {
                    Console::error("BitcoinPlugin: odrzucono cenę $price — odchylenie {$deltaPct}% od ostatniej ($lastPrice)");
                    return;
                }
            }
        }

        $line   = date('Y-m-d H:i:s') . ' ' . $price;
        $result = file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

        if ($result === false) {
            Console::error("BitcoinPlugin: nie udało się zapisać do pliku $file");
            return;
        }

        Console::info("BitcoinPlugin: dopisano wpis: $line");
    }

    public function draw(GdImage $img, array $data, Bounds $bounds): void
    {
        $priceStr    = $data['price'] !== null ? '$' . number_format((float)$data['price'], 0, ',', ' ') : 'N/A';
        //$isUp        = ($data['change'] ?? 0) >= 0;
        //$changeStr   = $data['change'] !== null
        //    ? ($isUp ? '▲' : '▼') . ' ' . number_format(abs((float)$data['change']), 2) . '%'
        //    : 'N/A';
        //$changeColor = $isUp ? '#34D399' : '#F87171';

        $headerH = 110; // px zarezerwowane na label + cenę + zmianę

        GdImageHelper::drawText($img, '₿  Bitcoin', $bounds->x, $bounds->y + 35,  20, '#8899CC', FONT);
        GdImageHelper::centerText($img, $priceStr,    $bounds->y + 95,  67, '#FFFFFF', FONT_BOLD);
        // GdImageHelper::centerText($img, $changeStr,   $bounds->y + 58, 28, $changeColor, FONT_BOLD);

        // Wykres dostaje resztę bounds
        $chartBounds = new Bounds(
            x:      $bounds->x,
            y:      $bounds->y + $headerH,
            width:  $bounds->width,
            height: $bounds->height - $headerH,
        );

        $this->drawChart($img, $data['history'], $chartBounds);
    }

    private function drawChart(GdImage $img, array $history, Bounds $bounds): void
    {
        if (count($history) < 2) {
            GdImageHelper::centerText($img, 'Brak danych historycznych', $bounds->y + 60, 30, '#556688', FONT);
            return;
        }

        // Paddingi wewnętrzne — skalowane względem bounds
        $padL = 90;   // miejsce na etykiety Y po lewej
        $padR = 20;
        $padT = 20;
        $padB = 40;   // miejsce na etykiety czasu na dole

        // Obszar rysowania wykresu
        $x0 = $bounds->x + $padL;
        $y0 = $bounds->y + $padT;
        $w  = $bounds->width  - $padL - $padR;
        $h  = $bounds->height - $padT - $padB;

        // Zakres cen
        $prices = array_column($history, 'price');
        $minP   = min($prices);
        $maxP   = max($prices);
        $rangeP = $maxP - $minP ?: 1;

        $n = count($history);

        // Siatka pozioma (4 linie)
        $gridColor = imagecolorallocatealpha($img, 100, 130, 200, 110);
        for ($gi = 0; $gi <= 4; $gi++) {
            $gy     = (int)($y0 + $h - $gi / 4 * $h);
            imageline($img, $x0, $gy, $x0 + $w, $gy, $gridColor);
            $gPrice = $minP + $gi / 4 * $rangeP;
            GdImageHelper::drawText($img, '$' . number_format($gPrice, 0, ',', ' '), $bounds->x + 8, $gy - 10, 18, '#556688', FONT);
        }

        // Kierunek wykresu
        $isChartUp = $history[$n - 1]['price'] >= $history[0]['price'];
        $fillR = $isChartUp ? 52  : 248;
        $fillG = $isChartUp ? 211 : 113;
        $fillB = $isChartUp ? 153 : 113;

        // Przeliczenie punktów
        $pts = [];
        foreach ($history as $i => $pt) {
            $pts[] = [
                (int)($x0 + $i / ($n - 1) * $w),
                (int)($y0 + $h - ($pt['price'] - $minP) / $rangeP * $h),
            ];
        }

        // Fill pod wykresem
        $bottom = $y0 + $h;
        foreach ($pts as $pt) {
            for ($fy = $pt[1]; $fy <= $bottom; $fy++) {
                $dist  = ($fy - $pt[1]) / max(1, $bottom - $pt[1]);
                $alpha = (int)(100 + 27 * $dist);
                imagesetpixel($img, $pt[0], $fy, imagecolorallocatealpha($img, $fillR, $fillG, $fillB, $alpha));
            }
        }

        // Linia wykresu
        $lineColor = imagecolorallocate($img, $fillR, $fillG, $fillB);
        imagesetthickness($img, 3);
        for ($i = 0; $i < $n - 1; $i++) {
            imageline($img, $pts[$i][0], $pts[$i][1], $pts[$i + 1][0], $pts[$i + 1][1], $lineColor);
        }
        imagesetthickness($img, 1);

        // Punkt końcowy
        [$lx, $ly] = $pts[$n - 1];
        imagefilledellipse($img, $lx, $ly, 14, 14, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, $lx, $ly, 8,  8,  $lineColor);

        // Etykiety czasu
        GdImageHelper::drawText($img, date('d.m H:i', $history[0]['ts']),      $x0,        $y0 + $h + 28, 20, '#556688', FONT);
        $this->rightText($img,        date('d.m H:i', $history[$n - 1]['ts']), $x0 + $w,   $y0 + $h + 28, 20, '#556688', FONT);
    }

    private function loadHistory(int $maxPoints = 72): array
    {
        $file = self::BTC_FILE;
        if (!file_exists($file)) return [];

        $lines  = array_filter(array_map('trim', file($file)));
        $lines  = array_values(array_slice($lines, -$maxPoints));

        $points = [];
        foreach ($lines as $line) {
            // Format: "2026-06-25 20:26:27 59543.26415465172"
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) ([\d.]+)$/', $line, $m)) continue;
            $points[] = [
                'ts'    => strtotime($m[1]),
                'price' => (float)$m[2],
            ];
        }
        return $points;
    }

    private function rightText(GdImage $img, string $text, int $xRight, int $y, int $size, string $hex, string $font): void
    {
        $rgb = sscanf($hex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $box = imagettfbbox($size, 0, $font, $text);
        $tw  = $box[2] - $box[0];
        imagettftext($img, $size, 0, $xRight - $tw, $y, $col, $font, $text);
    }
}