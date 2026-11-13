<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\Orbit;
use Swagnar\Igapi\Http\JsonClient;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Widoczne przeloty ISS nad Polską w ciągu doby od publikacji: liczone z aktualnego TLE (CelesTrak),
 * widoczne = ISS w słońcu, u obserwatora już ciemno (Słońce < −6°), wysokość nad horyzontem ≥ MIN_ELEVATION.
 * Dni bez takiego przelotu (zdarzają się tygodniami) są pomijane — w KOSMOS losuje się wtedy inny blok.
 */
class IssPlugin extends AbstractPlugin
{
    private const TLE_URL  = 'https://celestrak.org/NORAD/elements/gp.php?CATNR=25544&FORMAT=json';
    private const TIMEZONE = 'Europe/Warsaw';

    /** Obserwator w środku Polski — przelot widoczny stąd jest widoczny w większości kraju */
    private const OBSERVER = [19.4, 52.0];

    private const HOURS         = 24;
    private const STEP          = 20;   // s — krok liczenia przelotu
    private const MIN_ELEVATION = 20;   // ° — niżej ISS ginie w łunie i za budynkami
    private const SUN_DARK      = -6;   // ° — zmierzch cywilny
    private const MAX_PASSES    = 3;
    private const OVERHEAD      = 60;   // ° — wyżej kierunek na niebie przestaje mieć sens

    /** Kolory kolejnych przelotów — na mapie i w tabeli */
    private const COLORS = ['#7DD3FC', '#FBBF24', '#C4B5FD'];

    /** Mapa obejmuje zawsze całą Polskę i widoczne części przelotów (z marginesem), ale nie więcej niż MAX_VIEW */
    private const POLAND   = [14.1, 24.2, 49.0, 54.9];
    private const MAX_VIEW = [-5.0, 45.0, 40.0, 64.0];
    private const ASPECT   = 1.7;   // szerokość / wysokość mapy w km — tyle mniej więcej zostaje miejsca w pół slajdu

    private const DIRECTIONS = ['płn.', 'płn.-wsch.', 'wsch.', 'płd.-wsch.', 'płd.', 'płd.-zach.', 'zach.', 'płn.-zach.'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("IssPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'ISS nad Polską'; }

    public function getCredit(): string { return 'CelesTrak'; }

    public function canSkip(): bool
    {
        return true;
    }

    public function fetchData(): array
    {
        Console::plugin("IssPlugin: pobieram TLE z CelesTrak...");
        $gp = $this->http->get(self::TLE_URL)[0] ?? throw new \RuntimeException('CelesTrak: brak elementów orbity ISS');
        return $this->passes(new Orbit($gp), time());
    }

    /** Przeloty w ciągu doby od $from — osobno od fetchData(), żeby dało się je policzyć na inny dzień (test). */
    public function passes(Orbit $orbit, int $from): array
    {
        [$olon, $olat] = self::OBSERVER;
        $passes = [];
        $pass   = null;
        for ($t = $from; $t <= $from + self::HOURS * 3600; $t += self::STEP) {
            [$el, $az] = $orbit->lookAngles($t, $olon, $olat);
            if ($el < 0) {
                if ($pass !== null) {
                    $passes[] = $pass;
                    $pass     = null;
                }
                continue;
            }
            $visible = $orbit->sunlit($t) && Orbit::sunElevation($t, $olon, $olat) < self::SUN_DARK;
            $pass  ??= ['track' => [], 'visible' => []];
            [$lon, $lat]      = $orbit->subpoint($t);
            $pass['track'][]  = [round($lon, 3), round($lat, 3), (int) $visible];
            if ($visible) {
                $pass['visible'][] = ['t' => $t, 'el' => round($el, 1), 'az' => round($az)];
            }
        }

        // Zostają przeloty, których widoczna część wznosi się wystarczająco wysoko
        $shown = [];
        foreach ($passes as $p) {
            $vis = $p['visible'];
            if (!$vis || max(array_column($vis, 'el')) < self::MIN_ELEVATION) {
                continue;
            }
            $top     = $vis[array_search(max(array_column($vis, 'el')), array_column($vis, 'el'))];
            $shown[] = [
                'start'   => $vis[0]['t'],
                'end'     => end($vis)['t'],
                'top'     => $top['t'],
                'maxEl'   => $top['el'],
                'azStart' => $vis[0]['az'],
                'azEnd'   => end($vis)['az'],
                'elStart' => $vis[0]['el'],
                'elEnd'   => end($vis)['el'],
                'track'   => $p['track'],
            ];
        }

        if (!$shown) {
            return $this->skip('Brak widocznego przelotu ISS nad Polską w ciągu doby');
        }
        Console::success('IssPlugin: ' . count($shown) . ' widoczne przeloty, pierwszy o ' . date('H:i', $shown[0]['start']));
        return ['passes' => array_slice($shown, 0, self::MAX_PASSES), 'tleEpoch' => $orbit->epoch()];
    }

    /** Pola dla plugins/iss.json: nagłówek z pierwszym przelotem, wiersze tabeli i ślady przelotów na mapę. */
    public function present(array $data): array
    {
        $passes = $data['passes'];
        $first  = $passes[0];
        $count  = count($passes);

        $paths = [];
        foreach ($passes as $i => $p) {
            $paths[] = [
                'points' => $p['track'],
                'color'  => self::COLORS[$i % count(self::COLORS)],
                'label'  => $count > 1 ? (string) ($i + 1) : null,
            ];
        }

        return [
            'headline' => $count > 1 ? "ISS na polskim niebie · {$count} przeloty" : 'ISS na polskim niebie',
            'timeText' => $this->time($first['start']) . '–' . $this->time($first['end']),
            'subline'  => $this->describe($first),
            'rows'     => $count > 1 ? array_map(fn($i, $p) => [
                'cells' => [($i + 1) . '.', $this->time($p['start']) . '–' . $this->time($p['end']), $this->direction($p), 'maks. ' . (int) round($p['maxEl']) . '°'],
                'color' => self::COLORS[$i % count(self::COLORS)],
            ], array_keys($passes), $passes) : null,
            'paths'    => $paths,
            'view'     => $this->view($passes),
        ] + $data;
    }

    /** Zasięg mapy [lonMin, lonMax, latMin, latMax]: Polska + widoczne odcinki, dopasowane do proporcji mapy. */
    private function view(array $passes): array
    {
        [$lonMin, $lonMax, $latMin, $latMax] = self::POLAND;
        foreach ($passes as $p) {
            foreach ($p['track'] as [$lon, $lat, $lit]) {
                if ($lit) {
                    [$lonMin, $lonMax, $latMin, $latMax] = [min($lonMin, $lon - 1), max($lonMax, $lon + 1), min($latMin, $lat - 0.7), max($latMax, $lat + 0.7)];
                }
            }
        }
        [$lonMin, $lonMax, $latMin, $latMax] = [max($lonMin, self::MAX_VIEW[0]), min($lonMax, self::MAX_VIEW[1]), max($latMin, self::MAX_VIEW[2]), min($latMax, self::MAX_VIEW[3])];

        // Dopełnienie krótszego boku do proporcji mapy (stopień długości jest krótszy o cos φ)
        $cos   = cos(deg2rad(($latMin + $latMax) / 2));
        $width = ($lonMax - $lonMin) * $cos;
        $height = $latMax - $latMin;
        if ($width / $height < self::ASPECT) {
            $grow    = (self::ASPECT * $height - $width) / $cos / 2;
            [$lonMin, $lonMax] = [$lonMin - $grow, $lonMax + $grow];
        } else {
            $grow    = ($width / self::ASPECT - $height) / 2;
            [$latMin, $latMax] = [$latMin - $grow, $latMax + $grow];
        }
        return array_map(fn($v) => round($v, 2), [$lonMin, $lonMax, $latMin, $latMax]);
    }

    /** „maks. 64° nad horyzontem · z płn.-zach. na płd.-wsch.” */
    private function describe(array $pass): string
    {
        return sprintf('%s nad horyzontem · %s', 'maks. ' . (int) round($pass['maxEl']) . '°', $this->direction($pass));
    }

    /**
     * „z zach. na płd.-wsch.” — ale wysoko nad głową azymut skacze (ISS często gaśnie w cieniu Ziemi prawie
     * w zenicie), więc wtedy zamiast kierunku „pojawia się / znika nad głową”.
     */
    private function direction(array $pass): string
    {
        $dir      = fn(float $az) => self::DIRECTIONS[(int) round($az / 45) % 8];
        $fromHigh = ($pass['elStart'] ?? 0) >= self::OVERHEAD;
        $toHigh   = ($pass['elEnd'] ?? 0) >= self::OVERHEAD;
        return match (true) {
            $fromHigh && $toHigh => 'cały czas wysoko nad głową',
            $fromHigh            => 'pojawia się nad głową, znika na ' . $dir($pass['azEnd']),
            $toHigh              => 'z ' . $dir($pass['azStart']) . ', znika nad głową',
            default              => 'z ' . $dir($pass['azStart']) . ' na ' . $dir($pass['azEnd']),
        };
    }

    private function time(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('H:i');
    }
}
