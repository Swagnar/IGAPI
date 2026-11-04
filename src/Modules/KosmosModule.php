<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Plugins\AsteroidsPlugin;
use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Photo;
use Swagnar\Igapi\Layout\Separator;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Spacer;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;
use Swagnar\Igapi\Layout\WrappedText;
use DateTimeImmutable;
use RuntimeException;

class KosmosModule implements StoryModule
{
    private readonly AsteroidsPlugin $asteroids;

    public function __construct(private readonly JsonClient $http)
    {
        $this->asteroids = new AsteroidsPlugin($http);
    }

    public function getLabel(): string { return 'Kosmos'; }

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

    /**
     * Dane REMS z Curiosity docierają z opóźnieniem rzędu tygodni — pokazujemy wprost, jak stary jest pomiar,
     * zamiast udawać, że to pogoda "na dziś".
     */
    private function describeMeasurementAge(string $terrestrialDate): string
    {
        try {
            $measured = new DateTimeImmutable($terrestrialDate);
        } catch (\Throwable) {
            return 'data pomiaru nieznana';
        }

        $days = (int) $measured->diff(new DateTimeImmutable('today'))->days;
        $date = $measured->format('d.m.Y');

        return match (true) {
            $days === 0 => "pomiar z dziś ({$date})",
            $days === 1 => "pomiar z wczoraj ({$date})",
            default     => "pomiar z {$date} ({$days} dni temu)",
        };
    }

    private function valueOrDash(mixed $value): string
    {
        return ($value === null || $value === '' || $value === '--') ? '—' : (string) $value;
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
                'title' => 'statki znikają kadłubem, nie maleją',
                'text'  => 'Odpływający statek najpierw traci widoczność kadłuba, a maszt widać jeszcze długo potem. Na płaskiej powierzchni statek malałby równomiernie w całości, aż do zniknięcia w punkcie.',
            ],
            [
                'title' => 'Ziemia rzuca cień podczas zaćmienia Księżyca',
                'text'  => 'Przy każdym zaćmieniu Księżyca, niezależnie od pory roku czy kąta, cień Ziemi rzucany na Księżyc jest zawsze okrągły.',
            ],
            [
                'title' => 'na świecie są różne strefy czasowe',
                'text'  => 'Gdy w Warszawie jest południe, w Tokio jest już wieczór, a w Nowym Jorku dopiero świta. Na płaskiej Ziemi Słońce oświetlałoby cały dysk jednocześnie.',
            ],
            [
                'title' => 'jest coś takiego jak Krzyż Południa',
                'text'  => 'Gwiazdozbiór Krzyża Południa jest widoczny z półkuli południowej, ale nigdy z Europy czy Ameryki Północnej.',
            ],
            [
                'title' => 'ogarnęli to ponad 2000 lat temu',
                'text'  => 'Eratostenes zmierzył różnicę długości cieni w Aleksandrii i Syene w tym samym momencie, obliczając obwód Ziemi z dokładnością do kilku procent — używając wyłącznie patyka i geometrii.',
            ],
            [
                'title' => 'mamy zdjęcia',
                'text'  => 'Tysiące niezależnych satelitów, należących do dziesiątek krajów i firm, non-stop fotografuje kulistą Ziemię z orbity — łącznie z transmisjami na żywo z Międzynarodowej Stacji Kosmicznej.',
            ],
            [
                'title' => 'grawitacja wymusza kulistość',
                'text'  => 'Każdy wystarczająco masywny obiekt we wszechświecie — planety, księżyce, gwiazdy — przyjmuje kształt zbliżony do kuli, ponieważ grawitacja działa równomiernie we wszystkich kierunkach od środka masy.',
            ],
            [
                'title' => 'Słońce świeci pod różnymi kątami',
                'text'  => 'W tym samym momencie Słońce znajduje się wysoko nad Ekwadorem, a nisko nad Norwegią. Na płaskim dysku, przy jednym źródle światła, kąt padania promieni byłby identyczny wszędzie.',
            ],
            [
                'title' => 'ich samoloty nie mają sensu',
                'text'  => 'Bezpośrednie loty np. Sydney–Santiago de Chile trwają realnie tyle, ile przewiduje model kulistej Ziemi. Na płaskich mapach ta sama trasa byłaby wielokrotnie dłuższa.',
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

    public function run(): StoryPayload
    {
        $body = (new Stack())->add(new Spacer(30));

        // --- Mars: pogoda + status misji ---
        try {
            $body->add($this->weatherSection($this->fetchWeather()));
        } catch (\Throwable $e) {
            Console::error("Pogoda Mars failed: " . $e->getMessage());
        }
        $body->add($this->missionSection($this->computeMissionStatus()));
        $body->add(new Separator(marginTop: 25, marginBottom: 30));

        // --- Asteroidy ---
        try {
            $body->add($this->asteroids->component($this->asteroids->fetchData()));
            $body->add(new Separator(marginTop: 25, marginBottom: 30));
        } catch (\Throwable $e) {
            Console::error("Asteroidy failed: " . $e->getMessage());
        }

        $body->add($this->flatEarthSection($this->pickDailyFlatEarthFact()));

        // --- Zdjęcie na końcu — Photo dopasowuje się do miejsca, które zostało do stopki ---
        try {
            $photo = $this->fetchRandomPhoto();
            $body->add(new Separator(marginTop: 25, marginBottom: 30));
            $body->add($this->photoSection($photo));
        } catch (\Throwable $e) {
            Console::error("Zdjęcie Curiosity failed: " . $e->getMessage());
        }

        $slide = new Slide('KOSMOS', footer: Slide::source('Źródło: NASA JPL / NeoWs / CAB'));
        [$path, $url] = $slide->render($body)->save('kosmos');

        return StoryPayload::single($path, $url, $this->getLabel());
    }

    private function weatherSection(array $sol): Component
    {
        $min = $this->valueOrDash($sol['min_temp'] ?? null);
        $max = $this->valueOrDash($sol['max_temp'] ?? null);
        $sky = $this->translateAtmoOpacity($sol['atmo_opacity'] ?? 'Unknown');
        $uv  = $this->translateUvIndex($sol['local_uv_irradiance_index'] ?? 'Unknown');

        return (new Stack())
            ->add(new Text('Mars — pogoda w kraterze Gale', size: 28, color: '#FFFFFF', font: FONT_BOLD, height: 40))
            ->add(new Text("Sol {$sol['sol']} · " . $this->describeMeasurementAge($sol['terrestrial_date'] ?? ''), size: 20, color: '#AAAAAA', font: FONT, height: 36))
            ->add(new Text("Temperatura: {$min}°C do {$max}°C", size: 32, color: '#88CCFF', font: FONT_BOLD, height: 55))
            ->add(new Text('Ciśnienie: ' . $this->valueOrDash($sol['pressure'] ?? null) . ' Pa', size: 22, color: '#AAAAAA', font: FONT, height: 38))
            ->add(new Text("Niebo: {$sky} — UV: {$uv}", size: 22, color: '#AAAAAA', font: FONT, height: 34));
    }

    private function missionSection(array $status): Component
    {
        return new Text(
            "Curiosity: {$status['days_active']} dni na Marsie (~sol {$status['approx_sol']} misji)",
            size: 22, color: '#AAAAAA', font: FONT, height: 38,
        );
    }

    private function photoSection(array $photo): Component
    {
        $caption = null;
        if (!empty($photo['title'])) {
            $caption = GdImageHelper::translateToPolish($photo['title']);
            // Tłumacz potrafi przełożyć nazwę łazika dosłownie ("HiRISE obserwuje ciekawość...")
            $caption = preg_replace('/(?<!\p{L})[Cc]iekawoś(?:ć|ci|cią)(?!\p{L})/u', 'Curiosity', $caption);
            $caption = GdImageHelper::truncateAtWordBoundary($caption, 70);
        }

        return (new Stack())
            ->add(new Text('Zdjęcie dnia z Curiosity', size: 28, color: '#FFFFFF', font: FONT_BOLD, height: 60))
            ->add(new Photo(
                $photo['preview'],
                caption:      $caption,
                captionSize:  20,
                captionColor: '#AAAAAA',
                captionFont:  FONT,
            ));
    }

    private function flatEarthSection(array $fact): Component
    {
        return (new Stack())
            ->add(new Text('Jeśli spotkasz dziś płaskoziemce, powiedz mu że...', size: 24, color: '#FF6666', font: FONT_BOLD, height: 40))
            ->add(new Text($fact['title'], size: 28, color: '#FFFFFF', font: FONT_BOLD, height: 54))
            ->add(new Spacer(8))
            ->add(new WrappedText($fact['text'], size: 22, color: '#AAAAAA', font: FONT, lineHeight: 30));
    }
}
