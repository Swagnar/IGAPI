<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;
use DateTimeImmutable;
use GdImage;
use RuntimeException;

class MarsModule implements StoryModule
{
    public function __construct(private readonly JsonClient $http) {}

    public function getLabel(): string { return 'Mars'; }

    public function run(): StoryPayload
    {
        $img = GdImageHelper::createCanvas(WIDTH, HEIGHT);

        GdImageHelper::centerText($img, 'MARS', 150, HEADER_SIZE, '#FFFFFF', FONT_BOLD);

        $y = 200;

        // --- Pogoda ---
        try {
            $weather = $this->fetchWeather();
            $this->drawWeather($img, $weather, $y);
            $y += 200; // większy odstęp
        } catch (\Throwable $e) {
            Console::error("Pogoda Mars failed: " . $e->getMessage());
        }

        $this->drawSeparator($img, $y);
        $y += 60; // większy odstęp po separatorze

        // --- Status misji ---
        try {
            $status = $this->computeMissionStatus();
            $this->drawMissionStatus($img, $status, $y);
            $y += 150; // większy odstęp
        } catch (\Throwable $e) {
            Console::error("Status misji Curiosity failed: " . $e->getMessage());
        }

        $this->drawSeparator($img, $y);
        $y += 60; // większy odstęp po separatorze
        $photoHeight = 0;

        // --- Zdjęcie ---
        try {
            $photo = $this->fetchRandomPhoto();
            $photoHeight = $this->drawPhoto($img, $photo, $y);
        } catch (\Throwable $e) {
            Console::error("Zdjęcie Curiosity failed: " . $e->getMessage());
        }


        $y += $photoHeight + 70;   // podpis + odstęp
        $this->drawSeparator($img, $y);
        $y += 60;

        $this->drawFlatEarthFact($img, $y);

        GdImageHelper::centerText($img, 'Źródło: NASA JPL / CAB', HEIGHT - 40, 24, '#445577', FONT);

        [$path, $url] = GdImageHelper::saveImage($img, 'mars', PUBLIC_DIR, PUBLIC_URL);
        return StoryPayload::single($path, $url, $this->getLabel());
    }

    // ============================================================
    // SEPARATOR
    // ============================================================

    private function drawSeparator(GdImage $img, int $y): void
    {
        $color = imagecolorallocate($img, 0x33, 0x44, 0x55);
        imageline($img, 40, $y, WIDTH - 40, $y, $color);
    }

    // ============================================================
    // POGODA
    // ============================================================

    private function fetchWeather(): array
    {
        Console::info("Pobieram dane o pogodzie z krateru Gale...");
        $url = 'https://mars.nasa.gov/rss/api/?' . http_build_query([
            'feed'     => 'weather',
            'category' => 'msl',
            'feedtype' => 'json',
        ]);

        $data = $this->http->get($url);
        $latest = $data['soles'][0] ?? null;

        if ($latest === null) {
            throw new RuntimeException('Brak danych pogodowych z Gale Crater');
        }
        Console::success("Pobrano dane pogodowe z krateru Gale");
        return $latest;
    }

    private function drawWeather(GdImage $img, array $sol, int $y): void
    {
        GdImageHelper::centerText($img, "Pogoda w kraterze Gale", $y, 28, '#FFFFFF', FONT_BOLD);
        GdImageHelper::centerText($img, "Sol {$sol['sol']} ({$sol['terrestrial_date']})", $y + 50, 22, '#AAAAAA', FONT);
        GdImageHelper::drawText($img, "Temperatura: {$sol['min_temp']}°C do {$sol['max_temp']}°C", 40, $y + 90, 32, '#88CCFF', FONT_BOLD);
        GdImageHelper::drawText($img, "Cisnienie: {$sol['pressure']} Pa", 40, $y + 130, 22, '#AAAAAA', FONT);

        $sky = $this->translateAtmoOpacity($sol['atmo_opacity']);
        $uv  = $this->translateUvIndex($sol['local_uv_irradiance_index']);

        GdImageHelper::drawText($img, "Niebo: {$sky} — UV: {$uv}", 40, $y + 160, 22, '#AAAAAA', FONT);
    }

    // ============================================================
    // STATUS MISJI
    // ============================================================

    private function computeMissionStatus(): array
    {
        $landing = new DateTimeImmutable('2012-08-06');
        $now = new DateTimeImmutable();
        $daysActive = $landing->diff($now)->days;
        $approxSol = (int) floor($daysActive / 1.0275);

        return [
            'days_active' => $daysActive,
            'approx_sol'  => $approxSol,
        ];
    }

    private function drawMissionStatus(GdImage $img, array $status, int $y): void
    {
        GdImageHelper::drawText($img, "Curiosity — status: aktywny", 40, $y, 28, '#FFFFFF', FONT_BOLD);
        GdImageHelper::drawText($img, "{$status['days_active']} dni na Marsie (ladowanie 2012-08-06)", 40, $y + 40, 22, '#AAAAAA', FONT);
        GdImageHelper::drawText($img, "Przyblizony sol misji: ~{$status['approx_sol']}", 40, $y + 70, 22, '#AAAAAA', FONT);
    }

    private function fetchRandomPhoto(): array
    {
        Console::info("Pobieram losowe zdjęcie z Curiosity...");
        $url = 'https://images-api.nasa.gov/search?' . http_build_query([
            'q'          => 'curiosity mars rover',
            'media_type' => 'image',
            'year_start' => (int) date('Y') - 1,
        ]);

        $data = $this->http->get($url);
        $items = $data['collection']['items'] ?? [];

        if (empty($items)) {
            throw new RuntimeException('Brak zdjęć Curiosity w images-api.nasa.gov');
        }
        Console::success("Pobrano losowe zdjęcie z Curiosity");
        $item = $items[array_rand($items)];
        $preview = $item['links'][0]['href'] ?? null;

        if ($preview === null) {
            throw new RuntimeException('Brak miniaturki dla znalezionego zdjęcia');
        }

        return [
            'title'   => $item['data'][0]['title'] ?? '',
            'preview' => $preview,
        ];
    }

    private function getFlatEarthFacts(): array
    {
        return [
            [
                'title' => 'Statki znikają kadłubem, nie maleją',
                'text'  => 'Odpływający statek najpierw traci widoczność kadłuba, a maszt widać jeszcze długo potem. Na płaskiej powierzchni statek malałby równomiernie w całości, aż do zniknięcia w punkcie.',
            ],
            [
                'title' => 'Cień Ziemi podczas zaćmienia Księżyca',
                'text'  => 'Przy każdym zaćmieniu Księżyca, niezależnie od pory roku czy kąta, cień Ziemi rzucany na Księżyc jest zawsze okrągły. Tylko kula rzuca okrągły cień z każdej strony.',
            ],
            [
                'title' => 'Różne strefy czasowe',
                'text'  => 'Gdy w Warszawie jest południe, w Tokio jest już wieczór, a w Nowym Jorku dopiero świta. Na płaskiej Ziemi Słońce oświetlałoby cały dysk jednocześnie.',
            ],
            [
                'title' => 'Krzyż Południa',
                'text'  => 'Gwiazdozbiór Krzyża Południa jest widoczny z półkuli południowej, ale nigdy z Europy czy Ameryki Północnej. To efekt krzywizny Ziemi zasłaniającej inny fragment nieba.',
            ],
            [
                'title' => 'Eksperyment Eratostenesa (240 r. p.n.e.)',
                'text'  => 'Grecki uczony zmierzył różnicę długości cieni w Aleksandrii i Syene w tym samym momencie, obliczając obwód Ziemi z dokładnością do kilku procent — używając wyłącznie patyka i geometrii.',
            ],
            [
                'title' => 'Loty dookoła świata',
                'text'  => 'Samoloty regularnie okrążają Ziemię zarówno przez trasy polarne, jak i równikowe, zawsze wracając do punktu startu. Na płaskiej mapie trasy polarne byłyby fizycznie niemożliwe do przelecenia w rozsądnym czasie.',
            ],
            [
                'title' => 'Zdjęcia satelitarne i z ISS',
                'text'  => 'Tysiące niezależnych satelitów, należących do dziesiątek krajów i firm, non-stop fotografuje kulistą Ziemię z orbity — łącznie z transmisjami na żywo z Międzynarodowej Stacji Kosmicznej.',
            ],
            [
                'title' => 'Grawitacja i kształt dużych ciał',
                'text'  => 'Każdy wystarczająco masywny obiekt we wszechświecie — planety, księżyce, gwiazdy — przyjmuje kształt zbliżony do kuli, ponieważ grawitacja działa równomiernie we wszystkich kierunkach od środka masy.',
            ],
            [
                'title' => 'Różna wysokość Słońca na niebie',
                'text'  => 'W tym samym momencie Słońce znajduje się wysoko nad Ekwadorem, a nisko nad Norwegią. Na płaskim dysku, przy jednym źródle światła, kąt padania promieni byłby identyczny wszędzie.',
            ],
            [
                'title' => 'Loty non-stop na półkuli południowej',
                'text'  => 'Bezpośrednie loty np. Sydney–Santiago de Chile trwają realnie tyle, ile przewiduje model kulistej Ziemi. Na popularnych płaskich mapach azymutalnych ta sama trasa byłaby wielokrotnie dłuższa.',
            ],
        ];
    }

    private function pickDailyFlatEarthFact(): array
    {
        $facts = $this->getFlatEarthFacts();

        $seed = (int) date('Ymd');
        mt_srand($seed);
        $index = mt_rand(0, count($facts) - 1);
        mt_srand(); // reset seeda, żeby nie wpłynąć na inne losowania w tym samym requeście

        return $facts[$index];
    }

    private function drawFlatEarthFact(GdImage $img, int $y): void
    {
        $fact = $this->pickDailyFlatEarthFact();

        GdImageHelper::drawText($img, 'Jeśli spotkasz dziś płaskoziemce, powiedz mu że...', 40, $y, 24, '#FF6666', FONT_BOLD);
        GdImageHelper::drawText($img, $fact['title'], 40, $y + 50, 28, '#FFFFFF', FONT_BOLD);

        // zawijanie tekstu na kilka linii (~50 znaków na linię przy tej szerokości canvasa)
        $wrapped = wordwrap($fact['text'], 55, "\n", true);
        $lines = explode("\n", $wrapped);

        foreach ($lines as $i => $line) {
            GdImageHelper::drawText($img, $line, 40, $y + 90 + ($i * 30), 22, '#AAAAAA', FONT);
        }
    }

    private function drawPhoto(GdImage $img, array $photo, int $y): int
    {

        GdImageHelper::drawText($img, 'Zdjęcie dnia:', 40, $y - 20, 28, '#FFFFFF', FONT_BOLD);
        $y += 20;
        $context = stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $imageData = @file_get_contents($photo['preview'], false, $context);

        if ($imageData === false) {
            throw new RuntimeException("Nie udało się pobrać obrazka: {$photo['preview']}");
        }

        $photoImg = imagecreatefromstring($imageData);
        if ($photoImg === false) {
            throw new RuntimeException("Nieprawidłowy format obrazka: {$photo['preview']}");
        }

        $srcW = imagesx($photoImg);
        $srcH = imagesy($photoImg);

        $targetW = WIDTH - 80;
        $targetH = 400;

        $scale = min($targetW / $srcW, $targetH / $srcH);
        $drawW = (int) ($srcW * $scale);
        $drawH = (int) ($srcH * $scale);
        $offsetX = 40 + (int) (($targetW - $drawW) / 2);

        imagecopyresampled($img, $photoImg, $offsetX, $y, 0, 0, $drawW, $drawH, $srcW, $srcH);
        imagedestroy($photoImg);

        if (!empty($photo['title'])) {
            $translatedTitle = GdImageHelper::translateToPolish($photo['title']);
            GdImageHelper::drawText($img, mb_substr($translatedTitle, 0, 70), 40, $y + $drawH + 30, 20, '#AAAAAA', FONT);
        }
        return $drawH;
    }

    private function translateAtmoOpacity(string $value): string
    {
        $map = [
            'Sunny'      => 'Słonecznie',
            'Cloudy'     => 'Pochmurno',
            'Dusty'      => 'Zapylone',
            'Ice fog'    => 'Mgła lodowa',
            'Unknown'    => 'Nieznane',
        ];

        return $map[$value] ?? $value; // fallback: oryginał, gdyby API zwróciło coś nowego
    }

    private function translateUvIndex(string $value): string
    {
        $map = [
            'Low'        => 'Niski',
            'Moderate'   => 'Umiarkowany',
            'High'       => 'Wysoki',
            'Very High'  => 'Bardzo wysoki',
            'Unknown'    => 'Nieznany',
        ];

        return $map[$value] ?? $value;
    }
}