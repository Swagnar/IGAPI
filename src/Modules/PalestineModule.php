<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use DateTimeImmutable;
use GdImage;
use RuntimeException;

class PalestineModule implements StoryModule
{
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


        Console::info("Fonty dostępne, tworzę obraz...");
        $img = imagecreatetruecolor(WIDTH, HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        GdImageHelper::drawBackground($img);

        $latestTotal = $latest ? (int)($latest['killed_cum'] ?? 0) : null;
        if ($latestTotal > 0) {
            $this->drawDots($img, $latestTotal, WIDTH, HEIGHT);
        }
 
        GdImageHelper::centerText($img, 'GAZA',                           150,  HEADER_SIZE, '#ffffff', FONT_BOLD);
        GdImageHelper::centerText($img, $date,                            300,  40         , '#cfcfcf', FONT);
        GdImageHelper::centerText($img, 'Brak raportu dla tego dnia',     700,  48         , '#ffffff', FONT_BOLD);
    
        if ($latest !== null) {
            $latestDate = $latest['report_date'] ?? '—';
            GdImageHelper::centerText($img, 'Ostatni raport: ' . $latestDate,         960,  34, '#888888', FONT);
            GdImageHelper::centerText($img, 'Łącznie zabici',                        1060,  38, '#cfcfcf', FONT);
            GdImageHelper::centerText($img, $this->fmt($latestTotal),                       1160,  72, '#ffffff', FONT_BOLD);
    
            if (!empty($latest['ext_killed_children_cum'])) {
                GdImageHelper::centerText($img, 'w tym dzieci',                      1270,  34, '#cfcfcf', FONT);
                GdImageHelper::centerText($img, $this->fmt($latest['ext_killed_children_cum']), 1360, 60, '#ffd166', FONT_BOLD);
            }
            if (!empty($latest['ext_killed_women_cum'])) {
                GdImageHelper::centerText($img, 'w tym kobiety',                     1450,  34, '#cfcfcf', FONT);
                GdImageHelper::centerText($img, $this->fmt($latest['ext_killed_women_cum']), 1540, 60, '#ffd166', FONT_BOLD);
            }
            if (!empty($latest['ext_press_killed_cum'])) {
                GdImageHelper::centerText($img, 'dziennikarze',                      1630,  34, '#cfcfcf', FONT);
                GdImageHelper::centerText($img, $this->fmt($latest['ext_press_killed_cum']), 1720, 60, '#ffd166', FONT_BOLD);
            }
        }
    
        GdImageHelper::centerText($img, 'Źródło: Tech for Palestine', 1820, 28, '#aaaaaa', FONT);
    
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


        Console::info("Fonty dostępne, tworzę obraz...");
        $img = imagecreatetruecolor(WIDTH, HEIGHT);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        GdImageHelper::drawBackground($img);

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
        // $killedTotal = $report['killed'] ?? 0;

        $womenKilled = $report['ext_killed_women_cum'] ?? 0;
        $childrenKilled = $report['ext_killed_children_cum'] ?? 0;
        $pressKilled = $report['ext_press_killed_cum'] ?? 0;


        // ── Nagłówek ──────────────────────────────────────────────────────────────
        GdImageHelper::centerText($img, 'GAZA',             150,  72, '#ffffff', FONT_BOLD);
        GdImageHelper::centerText($img, $reportDate,    300,  40, '#cfcfcf', FONT);
    
        // ── Zabici tego dnia ──────────────────────────────────────────────────────
        GdImageHelper::centerText($img, 'Zabici tego dnia',        440,  38, '#cfcfcf', FONT);
        GdImageHelper::centerText($img, $this->fmt($killedToday), 580, 130, '#ffffff', FONT_BOLD);
    
        // ── Łącznie zabici ────────────────────────────────────────────────────────
        GdImageHelper::centerText($img, 'Łącznie zabici',          720,  38, '#cfcfcf', FONT);
        GdImageHelper::centerText($img, $this->fmt($total), 840,  72, '#ffffff', FONT_BOLD);
    
        // ── Zmiana vs poprzedni raport ────────────────────────────────────────────
        $diffPrefix = ($diff >= 0) ? '+' : '';
        $diffColor  = ($diff > 0)  ? '#ff6b6b' : '#aaaaaa';
        GdImageHelper::centerText($img, 'Zmiana vs poprzedni raport (' . $prevReportDate . ')', 930, 30, '#888888', FONT);
        GdImageHelper::centerText($img, $diffPrefix . $this->fmt($diff), 1020, 64, $diffColor, FONT_BOLD);
    
        // ── Dzieci / kobiety / dziennikarze ───────────────────────────────────────
        GdImageHelper::centerText($img, 'w tym dzieci',            1150, 34, '#cfcfcf', FONT);
        GdImageHelper::centerText($img, $this->fmt($childrenKilled), 1250, 64, '#ffd166', FONT_BOLD);
    
        GdImageHelper::centerText($img, 'w tym kobiety',           1340, 34, '#cfcfcf', FONT);
        GdImageHelper::centerText($img, $this->fmt($womenKilled), 1430, 64, '#ffd166', FONT_BOLD);
    
        GdImageHelper::centerText($img, 'dziennikarze',            1520, 34, '#cfcfcf', FONT);
        GdImageHelper::centerText($img, $this->fmt($pressKilled), 1610, 64, '#ffd166', FONT_BOLD);
    
        // ── Stopka ────────────────────────────────────────────────────────────────
        GdImageHelper::centerText($img, 'Źródło: Tech for Palestine', 1750, 28, '#aaaaaa', FONT);


        [$path, $url] = GdImageHelper::saveImage($img, $this->getLabel(), PUBLIC_DIR, PUBLIC_URL);

        // imagepng($img, $path, 9);
        // imagedestroy($img);
        if(!is_file($path)) {
            throw new RuntimeException("Nie udało się zapisać obrazu w " . $path);
        }

        Console::success("Zapisano obraz: " . $path);
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