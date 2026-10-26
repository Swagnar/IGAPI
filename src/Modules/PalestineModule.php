<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Stat;
use Swagnar\Igapi\Layout\Text;

use DateTimeImmutable;
use GdImage;

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

    private function describeDailyChange(int $diff): string {
        return match (true) {
            $diff > 0 => 'o ' . $this->fmt($diff) . ' więcej tego dnia',
            $diff < 0 => 'o ' . $this->fmt(abs($diff)) . ' mniej tego dnia',
            default   => 'tyle samo co w poprzednim raporcie',
        };
    }

    private function fmt(int|float|null $number): string {
        return number_format((int)($number ?? 0), 0, ',', ' ');
    }

    private function generateImageWithNoData(string $date, ?array $latest): array {
        $slide = $this->createSlide($latest !== null ? (int)($latest['killed_cum'] ?? 0) : 0);

        $body = (new Stack(gap: 55))
            ->add(new Text($date, size: 40, color: '#cfcfcf', font: FONT, align: 'center', height: 52, marginTop: 84))
            ->add(new Text('Brak raportu dla tego dnia', size: 48, color: '#ffffff', font: FONT_BOLD, align: 'center', height: 70, marginTop: 300));

        if ($latest !== null) {
            $body
                ->add(new Text('Ostatni raport: ' . ($latest['report_date'] ?? '—'), size: 34, color: '#888888', font: FONT, align: 'center'))
                ->add(new Stat('Łącznie zabici', $this->fmt($latest['killed_cum'] ?? 0), labelSize: 38, valueSize: 72));
            $this->addBreakdown($body, $latest);
        }

        return $slide->render($body)->save($this->getLabel());
    }

    private function generateImage(array $report, ?array $previous): array {
        // Łączna liczba zabitych
        $total         = (int)($report['killed_cum'] ?? 0);
        $previousTotal = (int)($previous['killed_cum'] ?? 0);

        // killed (dzienny) — preferuj pole 'killed', fallback na różnicę skumulowanych
        $killedToday    = isset($report['killed']) ? (int)$report['killed'] : ($total - $previousTotal);
        // Bez pola 'killed' w poprzednim raporcie nie da się rzetelnie porównać — wtedy pomijamy porównanie
        $killedPrevious = isset($previous['killed']) ? (int)$previous['killed'] : null;

        $slide = $this->createSlide($total);

        $body = (new Stack(gap: 55))
            ->add(new Text($report['report_date'] ?? 'N/A', size: 40, color: '#cfcfcf', font: FONT, align: 'center', height: 52, marginTop: 84))
            ->add(new Stat('Zabici tego dnia', $this->fmt($killedToday), labelSize: 38, valueSize: 130))
            ->add(new Stat('Łącznie zabici', $this->fmt($total), labelSize: 38, valueSize: 72));

        // Porównanie dziennej liczby zabitych z poprzednim raportem.
        // Wcześniej był tu sam "-38" z podpisem "Zmiana vs poprzedni raport", co wyglądało jak spadek
        // łącznej liczby ofiar. Teraz wprost: ile zginęło w poprzednim raporcie i o ile więcej/mniej.
        if ($killedPrevious !== null) {
            $diff = $killedToday - $killedPrevious;
            $body->add((new Stack())
                ->add(new Text('Poprzedni raport (' . ($previous['report_date'] ?? 'N/A') . ')', size: 30, color: '#888888', font: FONT, align: 'center'))
                ->add(new Text($this->fmt($killedPrevious) . ' zabitych', size: 52, color: '#aaaaaa', font: FONT_BOLD, align: 'center', height: 80))
                ->add(new Text($this->describeDailyChange($diff), size: 28, color: $diff > 0 ? '#ff6b6b' : '#888888', font: FONT, align: 'center', marginTop: 12)));
        }

        $this->addBreakdown($body, $report);

        [$path, $url] = $slide->render($body)->save($this->getLabel());
        Console::success("Zapisano obraz: " . $path);
        return [$path, $url];
    }

    /** Slajd z tytułem, stopką i tłem z kropek (1 kropka = 10 ofiar). */
    private function createSlide(int $total): Slide {
        $slide = new Slide('GAZA', footer: Slide::source('Źródło: Tech for Palestine', color: '#aaaaaa', size: 28), footerHeight: 160);
        if ($total > 0) {
            $this->drawDots($slide->image(), $total, WIDTH, HEIGHT);
        }
        return $slide;
    }

    /** Dzieci / kobiety / dziennikarze — tylko te pola, które są w raporcie. */
    private function addBreakdown(Stack $body, array $report): void {
        $rows = [
            'w tym dzieci'  => $report['ext_killed_children_cum'] ?? null,
            'w tym kobiety' => $report['ext_killed_women_cum'] ?? null,
            'dziennikarze'  => $report['ext_press_killed_cum'] ?? null,
        ];

        foreach ($rows as $label => $value) {
            if (!empty($value)) {
                $body->add(new Stat($label, $this->fmt($value), valueColor: '#ffd166'));
            }
        }
    }
}
