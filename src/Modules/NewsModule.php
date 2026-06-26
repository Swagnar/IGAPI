<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use DateTimeImmutable;
use RuntimeException;
use GdImage;

class NewsModule implements StoryModule
{
    private const BTC_FILE  = ROOT_DIR . '/btc_data.txt';

    public function __construct(
        private readonly JsonClient $http,
    ){
        Console::module("Moduł NewsModule zainicjalizowany");
    }

    public function getLabel(): string { return 'Wiadomości'; }

    public function run(): StoryPayload {
        try {
            $btcData = $this->getBTCData();
        } catch (\Throwable $e) {
            Console::error("Nie udało się pobrać danych BTC: " . $e->getMessage());
            $btcData = ['price' => null, 'change' => null];
        }
        Console::info("Pobrano dane BTC: $" . ($btcData['price'] ?? 'N/A'));

        if ($btcData['price'] !== null) {
            $this->appendBtcIfNeeded((float)$btcData['price']);
        }

        $btcHistory = $this->loadBtcHistory();

        $populationData = $this->getWorldPopulationData();
        Console::info("Populacja świata: " . number_format($populationData['count'], 0, ',', ' '));

        [$path, $url] = $this->generateImage($btcData, $btcHistory, $populationData);

        return StoryPayload::single($path, $url, 'Wiadomości');
    }


    private function getBTCData(): array {
        $dataUrl  = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd&include_24hr_change=true';
        $response = $this->http->getCurl($dataUrl);
        $data     = $response['data'];

        return [
            'price'  => $data['bitcoin']['usd']            ?? null,
            'change' => $data['bitcoin']['usd_24h_change'] ?? null,
        ];
    }

    private function appendBtcIfNeeded(float $price): void {
        $today     = date('Y-m-d');
        $nowLine   = date('Y-m-d H:i:s') . ' ' . $price;
        $file      = self::BTC_FILE;

        // Sprawdź czy już mamy wpis z dzisiaj z bieżącą godziną
        $lastLine  = '';
        if (file_exists($file)) {
            $handle = fopen($file, 'r');
            if ($handle) {
                $pos = -1;
                fseek($handle, 0, SEEK_END);
                $size = ftell($handle);
                $buffer = '';
                while (ftell($handle) > 0 && $pos >= -$size) {
                    fseek($handle, --$pos, SEEK_END);
                    $char = fread($handle, 1);
                    if ($char === "\n" && $buffer !== '') break;
                    $buffer = $char . $buffer;
                }
                fclose($handle);
                $lastLine = trim($buffer);
            }
        }

        // Jeśli ostatni wpis jest z tej samej minuty – nie dopisuj
        $lastTimestamp = substr($lastLine, 0, 16);  // "2026-06-25 20:26"
        $nowTimestamp  = date('Y-m-d H:i');
        if ($lastTimestamp === $nowTimestamp) {
            Console::debug("BTC: wpis z tej minuty już istnieje, pomijam.");
            return;
        }

        file_put_contents($file, $nowLine . "\n", FILE_APPEND | LOCK_EX);
        Console::info("BTC: dopisano wpis: $nowLine");
    }

    /**
     * Wczytuje historię BTC z pliku – ostatnie N wpisów (domyślnie 48h = ~48 wpisów przy 1/h).
     * Zwraca tablicę ['ts' => timestamp, 'price' => float]
     */
    private function loadBtcHistory(int $maxPoints = 72): array {
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


    private function getWorldPopulationData(): array {
        $base     = 8_119_301_000;
        $baseTime = mktime(0, 0, 0, 1, 1, 2025);
        $elapsed  = time() - $baseTime;
        $current  = $base + (int)($elapsed * 2.3);

        return [
            'count'       => $current,
            'daily_added' => 200_000,
        ];
    }


    private function generateImage(array $btcData, array $btcHistory, array $populationData): array
    {
        if (!is_dir(PUBLIC_DIR)) {
            mkdir(PUBLIC_DIR, 0775, true);
        }

        if (!file_exists(FONT))      throw new RuntimeException("Brak fontu: " . FONT);
        if (!file_exists(FONT_BOLD)) throw new RuntimeException("Brak fontu bold: " . FONT_BOLD);

        Console::info("Generuję obraz NewsModule...");

        $img = imagecreatetruecolor(WIDTH, HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        GdImageHelper::drawBackground($img);

        $y = OFFSET_Y;

        GdImageHelper::centerText($img, 'ŚWIAT', $y, HEADER_SIZE, '#FFFFFF', FONT_BOLD);
        $dateStr = (new DateTimeImmutable())->format('d.m.Y · H:i');
        GdImageHelper::centerText($img, $dateStr, $y + 52, 26, '#8899BB', FONT);

        $y += 140;

        // ── BTC Price Card ────────────────────────────────────────────────────────
        $y = $this->drawBtcPriceCard($img, $btcData, $y);

        $y += 40;

        // ── Wykres BTC ────────────────────────────────────────────────────────────
        $y = $this->drawBtcChart($img, $btcHistory, $y);

        $y += 60;

        // ── Populacja ─────────────────────────────────────────────────────────────
        $y = $this->drawPopulationCard($img, $populationData, $y);

        // ── Stopka ────────────────────────────────────────────────────────────────
        GdImageHelper::centerText($img, 'Źródło: CoinGecko · ONZ WPP 2024', HEIGHT - 60, 24, '#445577', FONT);

        // ── Zapis ─────────────────────────────────────────────────────────────────
        $filename = 'news-story-' . date('Ymd-His') . '.png';
        $path     = rtrim(PUBLIC_DIR, '/') . '/' . $filename;
        $url      = rtrim(PUBLIC_URL, '/') . '/' . $filename;

        imagepng($img, $path, 6);
        imagedestroy($img);

        Console::success("Zapisano obraz: $path");
        return [$path, $url];
    }



    private function drawBtcPriceCard(GdImage $img, array $btcData, int $y): int
    {
        $panelH = 100;

        $price      = $btcData['price'];
        $change     = $btcData['change'];
        $priceStr   = $price !== null ? '$' . number_format((float)$price, 0, ',', ' ') : 'N/A';
        $changeAbs  = $change !== null ? abs((float)$change) : null;
        $isUp       = $change !== null && (float)$change >= 0;
        $changeStr  = $changeAbs !== null ? ($isUp ? '▲' : '▼') . ' ' . number_format($changeAbs, 2) . '%' : 'N/A';
        $changeColor = $isUp ? '#34D399' : '#F87171';

        $cy = $y + 55;
        GdImageHelper::drawText($img, '₿  Bitcoin', 60, $cy, 30, '#8899CC', FONT);
        $bounds = GdImageHelper::drawText($img, $priceStr, 60, $cy + 88, 67, '#FFFFFF', FONT_BOLD);

        // Zmiana % – w pigu po prawej stronie

        GdImageHelper::drawText($img, $changeStr . '²⁴⁄',  $bounds[3] + 10, $y + 78,  28, $changeColor, FONT_BOLD);

        return $y + $panelH;
    }

    private function drawBtcChart(GdImage $img, array $history, int $y): int
    {
        if (count($history) < 2) {
            GdImageHelper::centerText($img, 'Brak danych historycznych', $y + 60, 30, '#556688', FONT);
            return $y + 120;
        }

        $chartH = 420;
        $chartW = WIDTH - 80;
        $padL   = 100;
        $padR   = 40;
        $padT   = 40;
        $padB   = 60;

        // Zakres
        $prices = array_column($history, 'price');
        $minP   = min($prices);
        $maxP   = max($prices);
        $rangeP = $maxP - $minP ?: 1;

        $x0 = 40 + $padL;
        $y0 = $y + $padT + 30;
        $w  = $chartW - $padL - $padR;
        $h  = $chartH - $padT - $padB - 30;

        $n  = count($history);

        // Siatka pozioma (4 linie)
        $gridColor = imagecolorallocatealpha($img, 100, 130, 200, 110);
        for ($gi = 0; $gi <= 4; $gi++) {
            $gy  = (int)($y0 + $h - $gi / 4 * $h);
            imageline($img, $x0, $gy, $x0 + $w, $gy, $gridColor);
            $gPrice = $minP + $gi / 4 * $rangeP;
            GdImageHelper::drawText($img, '$' . number_format($gPrice, 0, ',', ' '), 40 + 8, $gy - 10, 18, '#556688', FONT);
        }

        // Czy wykres rośnie czy spada?
        $firstPrice = $history[0]['price'];
        $lastPrice  = $history[$n - 1]['price'];
        $isChartUp  = $lastPrice >= $firstPrice;

        // Punkty wykresu
        $pts = [];
        foreach ($history as $i => $pt) {
            $px     = (int)($x0 + $i / ($n - 1) * $w);
            $py     = (int)($y0 + $h - ($pt['price'] - $minP) / $rangeP * $h);
            $pts[]  = [$px, $py];
        }

        // Fill pod wykresem (gradient-like z obszarem)
        $fillR = $isChartUp ? 52  : 248;
        $fillG = $isChartUp ? 211 : 113;
        $fillB = $isChartUp ? 153 : 113;

        // Rysuj fill jako pionowe linie od punktu do dołu wykresu
        foreach ($pts as $i => $pt) {
            for ($fy = $pt[1]; $fy <= $y0 + $h; $fy++) {
                $dist    = ($fy - $pt[1]) / max(1, $y0 + $h - $pt[1]);
                $alpha   = (int)(100 + 27 * $dist); // coraz bardziej transparentny ku dołowi
                $fc      = imagecolorallocatealpha($img, $fillR, $fillG, $fillB, $alpha);
                imagesetpixel($img, $pt[0], $fy, $fc);
            }
        }

        // Linia wykresu
        $lineColor = imagecolorallocate($img, $fillR, $fillG, $fillB);
        imagesetthickness($img, 3);
        for ($i = 0; $i < $n - 1; $i++) {
            imageline($img, $pts[$i][0], $pts[$i][1], $pts[$i+1][0], $pts[$i+1][1], $lineColor);
        }
        imagesetthickness($img, 1);

        // Punkt końcowy z ceną
        $lx = $pts[$n-1][0];
        $ly = $pts[$n-1][1];
        $dotColor = imagecolorallocate($img, 255, 255, 255);
        imagefilledellipse($img, $lx, $ly, 14, 14, $dotColor);
        imagefilledellipse($img, $lx, $ly, 8,  8,  $lineColor);

        // Etykiety czasu (pierwsza i ostatnia)
        $tsFirst = date('d.m H:i', $history[0]['ts']);
        $tsLast  = date('d.m H:i', $history[$n-1]['ts']);
        GdImageHelper::drawText($img, $tsFirst, $x0,          $y0 + $h + 30, 20, '#556688', FONT);
        $this->rightText(       $img, $tsLast,  $x0 + $w,     $y0 + $h + 30, 20, '#556688', FONT);

        return $y + $chartH;
    }

    private function drawPopulationCard(GdImage $img, array $popData, int $y): int
    {
        $panelH = 260;

        $count      = (int)$popData['count'];

        $countStr = number_format($count, 0, ',', ' ');

        $cy = $y + 55;

        GdImageHelper::drawText($img, 'Ilu nas jest?', 60, $cy, 30, '#FFFFFF', FONT);

        // Duża liczba
        GdImageHelper::centerText($img, $countStr, $cy + 85, 62, '#FFFFFF', FONT_BOLD);

        return $y + $panelH;
    }

    private function rightText(GdImage $img, string $text, int $xRight, int $y, int $size, string $hex, string $font): void
    {
        $rgb = sscanf($hex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $box = imagettfbbox($size, 0, $font, $text);
        $tw  = $box[2] - $box[0];
        imagettftext($img, $size, 0, $xRight - $tw, $y, $col, $font, $text);
    }

    // ── Pozostałe metody (niezmienione) ──────────────────────────────────────────

    private function getEarthquakeData(): array {
        $data    = $this->http->get('https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&minmagnitude=5&limit=1&orderby=time');
        $feature = $data['features'][0] ?? null;
        if (!$feature) return ['mag' => null, 'place' => 'brak danych'];
        return [
            'mag'   => (float)$feature['properties']['mag'],
            'place' => $this->http->translate($feature['properties']['place']),
        ];
    }

    private function getPeopleInSpaceData(): array {
        $data = $this->http->get('http://api.open-notify.org/astros.json');
        return [
            'number' => (int)($data['number'] ?? 0),
            'people' => $data['people']         ?? [],
        ];
    }
}