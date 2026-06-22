<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;

use DateTimeImmutable;
use RuntimeException;
use GdImage;

class PalestineModule implements StoryModule
{

    private const FONT      = ROOT_DIR . '/Inter-Regular.ttf';
    private const FONT_BOLD = ROOT_DIR . '/Inter-Bold.ttf';

    public function __construct(
        private readonly JsonClient $http,
    ){
        Console::module("Moduł PalestineModule zainicjalizowany");
    }
    
    public function getLabel(): string { return 'Palestyna'; }

    public function run(): StoryPayload {
        $dataUrl    = 'https://data.techforpalestine.org/api/v2/casualties_daily.min.json';
        
        Console::info("Rozpoczynam pobieranie danych z Tech for Palestine...");
        $dataResult       = $this->http->get($dataUrl);
        $yesterday  = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        
        // Raport z wczoraj
        $target     = $this->findByDate($dataResult, $yesterday);

        // Jeśli nie ma raportu z wczoraj to szukamy ostatniego dostepnego raportu
        if(!$target || !isset($target['killed_cum'])) {
            Console::info("Brak raportu z dnia: $yesterday, szukam ostatniego dostępnego raportu...");
            $latest = $this->findLatestTwoWithCum($dataResult)[0] ?? null;
            [$path, $url] = $this->generateImageWithNoData($yesterday, $latest);
            return StoryPayload::single($path, $url, 'Palestyna');
        }

        $previous   = $this->findPreviousReport($dataResult, $yesterday);
        Console::info("Pobrano raport z dnia: " . ($target['report_date'] ?? 'N/A') . " oraz poprzedni raport z dnia: " . ($previous['report_date'] ?? 'N/A'));    
        Console::info("Generuję obrazek...");
        [$path, $url] = $this->generateImage($target, $previous);
        return StoryPayload::single($path, $url, 'Palestyna');

    }

    private function generateImageWithNoData(string $date, ?array $latest): array {
        if(!is_dir(PUBLIC_DIR)) {
            mkdir(PUBLIC_DIR, 0755, true);
        }
        Console::info("Sprawdzam dostępność fontów...");

        if(!file_exists($this::FONT)) throw new RuntimeException("Brak fontu: $this::FONT");
        if(!file_exists($this::FONT_BOLD)) throw new RuntimeException("Brak fontu: $this::FONT_BOLD");

        Console::info("Fonty dostępne, tworzę obraz...");
        $img = imagecreatetruecolor(WIDTH, HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $bg = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $bg);

        $latestTotal = $latest ? (int)($latest['killed_cum'] ?? 0) : null;
        if ($latestTotal > 0) {
            $this->drawDots($img, $latestTotal, WIDTH, HEIGHT);
        }
 
        $this->centerText($img, 'GAZA',                           150,  72, '#ffffff', $this::FONT_BOLD);
        $this->centerText($img, $date,                            300,  40, '#cfcfcf', $this::FONT);
        $this->centerText($img, 'Brak raportu dla tego dnia',     700,  48, '#ffffff', $this::FONT_BOLD);
    
        if ($latest !== null) {
            $latestDate = $latest['report_date'] ?? '—';
            $this->centerText($img, 'Ostatni raport: ' . $latestDate,         960,  34, '#888888', $this::FONT);
            $this->centerText($img, 'Łącznie zabici',                        1060,  38, '#cfcfcf', $this::FONT);
            $this->centerText($img, $this->fmt($latestTotal),                       1160,  72, '#ffffff', $this::FONT_BOLD);
    
            if (!empty($latest['ext_killed_children_cum'])) {
                $this->centerText($img, 'w tym dzieci',                      1270,  34, '#cfcfcf', $this::FONT);
                $this->centerText($img, $this->fmt($latest['ext_killed_children_cum']), 1360, 60, '#ffd166', $this::FONT_BOLD);
            }
            if (!empty($latest['ext_killed_women_cum'])) {
                $this->centerText($img, 'w tym kobiety',                     1450,  34, '#cfcfcf', $this::FONT);
                $this->centerText($img, $this->fmt($latest['ext_killed_women_cum']), 1540, 60, '#ffd166', $this::FONT_BOLD);
            }
            if (!empty($latest['ext_press_killed_cum'])) {
                $this->centerText($img, 'dziennikarze',                      1630,  34, '#cfcfcf', $this::FONT);
                $this->centerText($img, $this->fmt($latest['ext_press_killed_cum']), 1720, 60, '#ffd166', $this::FONT_BOLD);
            }
        }
    
        $this->centerText($img, 'Źródło: Tech for Palestine', 1820, 28, '#aaaaaa', $this::FONT);
    
        $filename = 'gaza-story-' . $date . '.png';
        $path     = PUBLIC_DIR . '/' . $filename;
        $url      = PUBLIC_URL . '/' . $filename;
    
        imagepng($img, $path, 9);
        imagedestroy($img);
    
        return [$path, $url];
    }

    private function generateImage(array $report, array $previous): array {
        if(!is_dir(PUBLIC_DIR)) {
            mkdir(PUBLIC_DIR, 0755, true);
        }
        Console::info("Sprawdzam dostępność fontów...");

        if(!file_exists($this::FONT)) throw new RuntimeException("Brak fontu: $this::FONT");
        if(!file_exists($this::FONT_BOLD)) throw new RuntimeException("Brak fontu: $this::FONT_BOLD");

        Console::info("Fonty dostępne, tworzę obraz...");
        $img = imagecreatetruecolor(WIDTH, HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $bg = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $bg);

        // Łączna liczba zabitych
        $total = (int)($report['killed_cum'] ?? 0);
        $previousTotal = (int)($previous['killed_cum'] ?? 0);  
        
        // killed (dzienny) — preferuj pole 'killed', fallback na różnicę skumulowanych
        $killedToday    = isset($report['killed']) ? (int)$report['killed'] : ($total - $previousTotal);
        $killedPrevious = isset($previous['killed']) ? (int)$previous['killed'] : ($previousTotal - 0);
        
        // diff = zmiana dziennej liczby zabitych (np. -1 = wczoraj zginęło mniej niż przedwczoraj)
        $diff = $killedToday - $killedPrevious;

        // Tło z kropek
        if($total > 0) {
            $this->drawDots($img, $total, WIDTH, HEIGHT);
        }

        // Dane z raportu
        $reportDate = $report['report_date'] ?? 'N/A';
        $prevReportDate = $previous['report_date'] ?? 'N/A';
        $killedTotal = $report['killed'] ?? 0;

        $womenKilled = $report['ext_killed_women_cum'] ?? 0;
        $childrenKilled = $report['ext_killed_children_cum'] ?? 0;
        $pressKilled = $report['ext_press_killed_cum'] ?? 0;


        // ── Nagłówek ──────────────────────────────────────────────────────────────
        $this->centerText($img, 'GAZA',             150,  72, '#ffffff', $this::FONT_BOLD);
        $this->centerText($img, $reportDate,    300,  40, '#cfcfcf', $this::FONT);
    
        // ── Zabici tego dnia ──────────────────────────────────────────────────────
        $this->centerText($img, 'Zabici tego dnia',        440,  38, '#cfcfcf', $this::FONT);
        $this->centerText($img, $this->fmt($killedToday), 580, 130, '#ffffff', $this::FONT_BOLD);
    
        // ── Łącznie zabici ────────────────────────────────────────────────────────
        $this->centerText($img, 'Łącznie zabici',          720,  38, '#cfcfcf', $this::FONT);
        $this->centerText($img, $this->fmt($killedTotal), 840,  72, '#ffffff', $this::FONT_BOLD);
    
        // ── Zmiana vs poprzedni raport ────────────────────────────────────────────
        $diffPrefix = ($diff >= 0) ? '+' : '';
        $diffColor  = ($diff > 0)  ? '#ff6b6b' : '#aaaaaa';
        $this->centerText($img, 'Zmiana vs poprzedni raport (' . $prevReportDate . ')', 930, 30, '#888888', $this::FONT);
        $this->centerText($img, $diffPrefix . $this->fmt($diff), 1020, 64, $diffColor, $this::FONT_BOLD);
    
        // ── Dzieci / kobiety / dziennikarze ───────────────────────────────────────
        $this->centerText($img, 'w tym dzieci',            1150, 34, '#cfcfcf', $this::FONT);
        $this->centerText($img, $this->fmt($childrenKilled), 1250, 64, '#ffd166', $this::FONT_BOLD);
    
        $this->centerText($img, 'w tym kobiety',           1340, 34, '#cfcfcf', $this::FONT);
        $this->centerText($img, $this->fmt($womenKilled), 1430, 64, '#ffd166', $this::FONT_BOLD);
    
        $this->centerText($img, 'dziennikarze',            1520, 34, '#cfcfcf', $this::FONT);
        $this->centerText($img, $this->fmt($pressKilled), 1610, 64, '#ffd166', $this::FONT_BOLD);
    
        // ── Stopka ────────────────────────────────────────────────────────────────
        $this->centerText($img, 'Źródło: Tech for Palestine', 1750, 28, '#aaaaaa', $this::FONT);


        Console::info("Zapisuję obraz...");
        $filename = 'gaza-story-' . $report['report_date'] . '.png';
        $path = PUBLIC_DIR . '/' . $filename;
        $url = PUBLIC_URL . '/' . $filename;

        imagepng($img, $path, 9);
        imagedestroy($img);
        Console::info("Zapisano obraz");
        return [$path,$url];
    }

    private function findLatestTwoWithCum(array $data): array {
        $result = [];
        foreach(array_reverse($data) as $row) {
            if (isset($row['killed_cum'])) {
                $result[] = $row;
                if (count($result) === 2) break;
            }
        }
        return $result;
    }

    private function centerText(GdImage $img, string $text, int $y, int $size, string $colorHex, string $font) {
        $rgb = sscanf($colorHex, "#%02x%02x%02x");
        $col = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];
        $x = (1080 - $width) / 2;
        imagettftext($img, $size, 0, (int)$x, $y, $col, $font, $text);
    }

    private function findByDate(array $data, string $date): ?array {
        foreach($data as $row) {
            if(($row['report_date'] ?? null) === $date) {
                return $row;
            }
        }
        return null;
    }

    private function findPreviousReport(array $data, string $currentDate): ?array {
        $found = false;
        foreach(array_reverse($data) as $row) {
            if (!isset($row['killed_cum'])) continue;
            if (!$found) {
                if (($row['report_date'] ?? null) === $currentDate) {
                    $found = true;
                }
                continue;
            }
            return $row; // pierwszy wcześniejszy z killed_cum
        }
        return null;
    }

    private function drawDots(GdImage $img, int $total, int $width, int $height) {
        $victimsPerDot           = 10;
        $maxVictimsForFullHeight = 100000;
        $dots                    = (int)ceil($total / $victimsPerDot);
        $cols                    = 90;
        $dotSize                 = 5;
        $gap                     = 7;
        $rowsForFullHeight       = (int)ceil(($maxVictimsForFullHeight / $victimsPerDot) / $cols);
        $rowHeight               = $height / $rowsForFullHeight;
        $rows                    = (int)ceil($dots / $cols);
        $usedHeight              = $rows * $rowHeight;
        $startX                  = (int)(($width - (($cols - 1) * $gap)) / 2);
        $startY                  = (int)($height - $usedHeight);
        $dot                     = imagecolorallocatealpha($img, 255, 255, 255, 95);
    
        for ($i = 0; $i < $dots; $i++) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $x   = (int)round($startX + ($col * $gap));
            $y   = (int)round($startY + ($row * $rowHeight));
            imagefilledellipse($img, $x, $y, $dotSize, $dotSize, $dot);
        }
    }

    private function fmt(int|float|null $number): string {
        return number_format((int)($number ?? 0), 0, ',', ' ');
    }
}
?>