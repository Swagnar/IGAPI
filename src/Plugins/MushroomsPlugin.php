<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Grzybobranie: szansa na grzyby w największych kompleksach leśnych Polski, liczona z pogody ostatnich dni
 * (Open-Meteo, jedno zapytanie dla wszystkich lasów). Wzór grzybiarzy: grzyby wychodzą 1–2 tygodnie po porządnym
 * deszczu, najlepiej przy 10–18°C, a przymrozki je zatrzymują. Poza sezonem (grudzień–maj) i w dni, gdy w żadnym
 * lesie szansa nie przekracza 5 % (susza, mróz), plugin się pomija — w puli losuje się wtedy inny blok.
 * Wygląd — mapa cieplna Polski jak w pogodzie, komentarz z `variants` w plugins/mushrooms.json.
 */
class MushroomsPlugin extends AbstractPlugin
{
    /** Kompleksy leśne: nazwa → [lat, lon] (mniej więcej środek lasu) */
    private const FORESTS = [
        'Bory Tucholskie'        => [53.65, 17.85],
        'Puszcza Notecka'        => [52.75, 16.20],
        'Puszcza Białowieska'    => [52.72, 23.80],
        'Puszcza Augustowska'    => [53.90, 23.20],
        'Puszcza Piska'          => [53.60, 21.70],
        'Kaszuby'                => [54.20, 17.90],
        'Puszcza Kampinoska'     => [52.33, 20.55],
        'Bory Dolnośląskie'      => [51.40, 15.40],
        'Lasy Janowskie'         => [50.65, 22.40],
        'Roztocze'               => [50.55, 23.00],
        'Góry Świętokrzyskie'    => [50.88, 21.00],
        'Bieszczady'             => [49.20, 22.40],
        'Beskidy'                => [49.60, 19.40],
        'Puszcza Drawska'        => [53.35, 15.90],
        'Lasy Stobrawskie'       => [50.95, 17.70],
    ];

    /** Sezon grzybowy: czerwiec–listopad */
    private const SEASON = [6, 7, 8, 9, 10, 11];

    private const VIEW = [14.1, 24.2, 49.0, 54.9];

    /** Szansa 0–100 %: brąz (sucho / zimno) → żółty → zieleń (wysyp) */
    private const PALETTE = [
        [0,   [120, 90, 70], 100],
        [25,  [190, 150, 60], 95],
        [50,  [210, 200, 60], 90],
        [75,  [110, 200, 90], 85],
        [100, [40, 170, 90], 80],
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("MushroomsPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Grzybobranie'; }

    public function getCredit(): string { return 'Open-Meteo'; }

    public function canSkip(): bool { return true; }

    public function fetchData(): array
    {
        if (!in_array((int) date('n'), self::SEASON, true)) {
            return $this->skip('poza sezonem grzybowym (czerwiec–listopad)');
        }

        Console::plugin("MushroomsPlugin: pobieram pogodę z ostatnich 2 tygodni dla lasów...");
        $coords = array_values(self::FORESTS);
        $result = $this->http->get('https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude'      => implode(',', array_column($coords, 0)),
            'longitude'     => implode(',', array_column($coords, 1)),
            'daily'         => 'precipitation_sum,temperature_2m_mean,temperature_2m_min',
            'past_days'     => 14,
            'forecast_days' => 1,
            'timezone'      => 'Europe/Warsaw',
        ]));
        if (!is_array($result) || count($result) !== count($coords)) {
            throw new RuntimeException('Open-Meteo: zła liczba lokalizacji w odpowiedzi');
        }

        $forests = [];
        foreach (array_keys(self::FORESTS) as $i => $name) {
            $daily = $result[$i]['daily'] ?? [];
            // Ostatni element to dziś (niepełny) — liczymy tylko dni zakończone
            $rain  = array_slice($daily['precipitation_sum'] ?? [], 0, -1);
            $mean  = array_slice($daily['temperature_2m_mean'] ?? [], 0, -1);
            $min   = array_slice($daily['temperature_2m_min'] ?? [], 0, -1);
            if (count($rain) < 14 || count($mean) < 7) {
                Console::warn("Brak danych dla {$name}");
                continue;
            }
            $forests[$name] = [
                'lat'    => self::FORESTS[$name][0],
                'lon'    => self::FORESTS[$name][1],
                'rain'   => round(array_sum($rain), 1),                        // mm z 14 dni
                'temp'   => round(array_sum(array_slice($mean, -7)) / 7, 1),   // średnia z tygodnia
                'frosts' => count(array_filter(array_slice($min, -5), fn($t) => $t !== null && $t < -2)),
            ];
        }
        if (!$forests) {
            throw new RuntimeException('Open-Meteo: brak danych dla lasów');
        }
        $best = max(array_map(fn($f) => self::score($f['rain'], $f['temp'], $f['frosts']), $forests));
        if ($best <= 5) {
            return $this->skip("w lasach pusto — najlepsza szansa {$best}% (za sucho albo za zimno)");
        }

        Console::success(sprintf('MushroomsPlugin: %d lasów, średnio %.0f mm deszczu w 2 tygodnie', count($forests), array_sum(array_column($forests, 'rain')) / count($forests)));
        return ['date' => date('Y-m-d'), 'forests' => $forests];
    }

    /**
     * Szansa na grzyby 0–100:
     *   deszcz z 14 dni: 0 mm → 0, ≥ 50 mm → 1
     *   średnia temperatura tygodnia: < 4°C → 0, 10–18°C → 1, > 26°C → 0 (liniowo pomiędzy)
     *   przymrozki (< −2°C) w ostatnich 5 dniach: każdy odbiera 25 %
     */
    public static function score(float $rain, float $temp, int $frosts): int
    {
        $wet  = min(1, max(0, $rain / 50));
        $warm = match (true) {
            $temp < 4   => 0,
            $temp < 10  => ($temp - 4) / 6,
            $temp <= 18 => 1,
            $temp < 26  => (26 - $temp) / 8,
            default     => 0,
        };
        return (int) round(100 * $wet * $warm * max(0, 1 - 0.25 * $frosts));
    }

    /** Pola dla plugins/mushrooms.json: punkty mapy, najlepszy las, średnia szansa i opis warunków. */
    public function present(array $data): array
    {
        $scores = [];
        foreach ($data['forests'] as $name => $f) {
            $scores[$name] = self::score($f['rain'], $f['temp'], $f['frosts']);
        }
        arsort($scores);
        $best = array_key_first($scores);
        $top  = array_slice(array_keys($scores), 0, 3);   // z nazwą nad procentem i pierwszeństwem przy etykietach

        $points = [];
        foreach ($data['forests'] as $name => $f) {
            $points[] = [
                'lon'      => $f['lon'],
                'lat'      => $f['lat'],
                'value'    => $scores[$name],
                'label'    => "{$scores[$name]}%",
                'sub'      => in_array($name, $top, true) ? $name : null,
                'color'    => $this->scoreColor($scores[$name]),
                'priority' => in_array($name, $top, true),
            ];
        }

        $avg   = (int) round(array_sum($scores) / count($scores));
        $rain  = array_sum(array_column($data['forests'], 'rain')) / count($data['forests']);
        $temp  = array_sum(array_column($data['forests'], 'temp')) / count($data['forests']);
        $frost = count(array_filter($data['forests'], fn($f) => $f['frosts'] > 0));

        return [
            'dateText'  => date('d.m', strtotime($data['date'])),
            'bestText'  => "Najlepiej: {$best} — {$scores[$best]}%",
            'bestColor' => $this->scoreColor($scores[$best]),
            'avgScore'   => $avg,
            'conditions' => sprintf('deszcz z 2 tygodni średnio %s mm · tydzień %s°C%s',
                number_format($rain, 0, ',', ' '), number_format($temp, 1, ',', ''),
                $frost ? ' · przymrozki w ' . $frost . ' ' . ($frost === 1 ? 'lesie' : 'lasach') : ''),
            'points'    => $points,
            'palette'   => self::PALETTE,
            'view'      => self::VIEW,
        ] + $data;
    }

    private function scoreColor(int $score): string
    {
        return match (true) {
            $score >= 70 => '#4ADE80',
            $score >= 45 => '#BEF264',
            $score >= 25 => '#FACC15',
            default      => '#D6A77A',
        };
    }
}
