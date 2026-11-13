<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Układ planet dziś: położenia ośmiu planet liczone lokalnie z elementów orbit Keplera (JPL, „Approximate Positions
 * of the Planets”, tabela 1, ważna 1800–2050 — błąd rzędu minut kątowych, w tej skali niewidoczny). Do tego
 * odległość każdej planety od Ziemi, gdzie jest na niebie względem Słońca (wieczorem / rano / całą noc)
 * i najbliższe opozycje Marsa, Jowisza i Saturna. Bez zapytań do sieci. Wygląd — jeden komponent SolarSystem
 * ze wszystkimi planetami w ściśniętej skali odległości (radial: log), Ziemia z przodu kadru.
 */
class PlanetsPlugin extends AbstractPlugin
{
    private const AU_KM = 149_597_870.7;

    /**
     * Elementy J2000 i zmiany na stulecie: a (au), e, I (°), L (°), ϖ (°), Ω (°) — każdy jako [wartość, zmiana].
     * Ziemia to barycentrum Ziemia–Księżyc.
     */
    private const ELEMENTS = [
        'Merkury' => [[0.38709927, 0.00000037], [0.20563593, 0.00001906], [7.00497902, -0.00594749], [252.25032350, 149472.67411175], [77.45779628, 0.16047689], [48.33076593, -0.12534081]],
        'Wenus'   => [[0.72333566, 0.00000390], [0.00677672, -0.00004107], [3.39467605, -0.00078890], [181.97909950, 58517.81538729], [131.60246718, 0.00268329], [76.67984255, -0.27769418]],
        'Ziemia'  => [[1.00000261, 0.00000562], [0.01671123, -0.00004392], [-0.00001531, -0.01294668], [100.46457166, 35999.37244981], [102.93768193, 0.32327364], [0.0, 0.0]],
        'Mars'    => [[1.52371034, 0.00001847], [0.09339410, 0.00007882], [1.84969142, -0.00813131], [-4.55343205, 19140.30268499], [-23.94362959, 0.44441088], [49.55953891, -0.29257343]],
        'Jowisz'  => [[5.20288700, -0.00011607], [0.04838624, -0.00013253], [1.30439695, -0.00183714], [34.39644051, 3034.74612775], [14.72847983, 0.21252668], [100.47390909, 0.20469106]],
        'Saturn'  => [[9.53667594, -0.00125060], [0.05386179, -0.00050991], [2.48599187, 0.00193609], [49.95424423, 1222.49362201], [92.59887831, -0.41897216], [113.66242448, -0.28867794]],
        'Uran'    => [[19.18916464, -0.00196176], [0.04725744, -0.00004397], [0.77263783, -0.00242939], [313.23810451, 428.48202785], [170.95427630, 0.40805281], [74.01692503, 0.04240589]],
        'Neptun'  => [[30.06992276, 0.00026291], [0.00859048, 0.00005105], [1.77004347, 0.00035372], [-55.12002969, 218.45945325], [44.96476227, -0.32241464], [131.78422574, -0.00508664]],
    ];

    /** Kolory i wielkości kropek na rysunku */
    private const LOOK = [
        'Merkury' => ['#A8A29E', 7],
        'Wenus'   => ['#FDE68A', 10],
        'Ziemia'  => ['#38BDF8', 11],
        'Mars'    => ['#F87171', 9],
        'Jowisz'  => ['#FDBA74', 14],
        'Saturn'  => ['#FCD34D', 12],
        'Uran'    => ['#67E8F9', 10],
        'Neptun'  => ['#818CF8', 10],
    ];


    /** Planety, dla których szukamy najbliższej opozycji (najlepsza widoczność w roku) */
    private const OPPOSITIONS = ['Mars', 'Jowisz', 'Saturn'];

    public function __construct()
    {
        Console::boot("PlanetsPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Układ planet dziś'; }

    public function getCredit(): string { return 'NASA'; }   // elementy orbit JPL — obliczenia lokalne

    public function fetchData(): array
    {
        Console::plugin("PlanetsPlugin: liczę położenia planet...");
        $date = (new DateTimeImmutable('today 12:00', new DateTimeZone('UTC')));
        $ts   = $date->getTimestamp();

        $positions = [];
        foreach (array_keys(self::ELEMENTS) as $name) {
            $positions[$name] = array_map(fn($v) => round($v, 5), self::position($name, $ts));
        }

        // Najbliższa opozycja: różnica długości ekliptycznych planety i Słońca widziana z Ziemi przechodzi przez 180°
        $oppositions = [];
        foreach (self::OPPOSITIONS as $name) {
            $prev = self::elongation($name, $ts - 86400);
            for ($day = 0; $day <= 800; $day++) {
                $d = self::elongation($name, $ts + $day * 86400);
                if (abs($d - $prev) > 180) {
                    // Przeskok przez ±180° — opozycja między poprzednim a tym dniem; bierzemy bliższy
                    $closer = (180 - abs($prev)) < (180 - abs($d)) ? $day - 1 : $day;
                    $oppositions[$name] = $date->modify("{$closer} days")->format('Y-m-d');
                    break;
                }
                $prev = $d;
            }
        }

        Console::success('PlanetsPlugin: najbliższe opozycje — ' . implode(', ', array_map(fn($n, $d) => "{$n} {$d}", array_keys($oppositions), $oppositions)));
        return ['date' => $date->format('Y-m-d'), 'positions' => $positions, 'oppositions' => $oppositions];
    }

    /** Heliocentryczne [x, y, z] w au, ekliptyka J2000, w chwili $ts. */
    public static function position(string $name, float $ts): array
    {
        return self::orbitPoint($name, $ts, null);
    }

    /**
     * Punkt orbity: z $meanAnomaly = null — położenie w chwili $ts, w przeciwnym razie punkt orbity (z elementów
     * na chwilę $ts) dla podanej anomalii średniej w stopniach — do rysowania całej elipsy.
     */
    private static function orbitPoint(string $name, float $ts, ?float $meanAnomaly): array
    {
        $T = ($ts - 946728000) / (36525 * 86400);   // stulecia od J2000.0 (2000-01-01 12:00 UTC)
        [$a, $e, $i, $L, $peri, $node] = array_map(fn($el) => $el[0] + $el[1] * $T, self::ELEMENTS[$name]);

        $omega = deg2rad($peri - $node);
        $M     = deg2rad($meanAnomaly ?? fmod($L - $peri, 360));
        $E     = $M;
        for ($k = 0; $k < 10; $k++) {
            $E -= ($E - $e * sin($E) - $M) / (1 - $e * cos($E));
        }
        $xp = $a * (cos($E) - $e);
        $yp = $a * sqrt(1 - $e * $e) * sin($E);

        [$cw, $sw, $cn, $sn, $ci, $si] = [cos($omega), sin($omega), cos(deg2rad($node)), sin(deg2rad($node)), cos(deg2rad($i)), sin(deg2rad($i))];
        return [
            ($cw * $cn - $sw * $sn * $ci) * $xp + (-$sw * $cn - $cw * $sn * $ci) * $yp,
            ($cw * $sn + $sw * $cn * $ci) * $xp + (-$sw * $sn + $cw * $cn * $ci) * $yp,
            ($sw * $si) * $xp + ($cw * $si) * $yp,
        ];
    }

    /** Elongacja w stopniach (−180…180): długość ekliptyczna planety minus Słońca, widziana z Ziemi. + = na wschód od Słońca. */
    private static function elongation(string $name, float $ts): float
    {
        $earth = self::position('Ziemia', $ts);
        $p     = self::position($name, $ts);
        $sun   = atan2(-$earth[1], -$earth[0]);
        $geo   = atan2($p[1] - $earth[1], $p[0] - $earth[0]);
        $d     = rad2deg($geo - $sun);
        return $d - 360 * round($d / 360);
    }

    /** Pola dla plugins/planets.json: planety dla SolarSystem, tabela widoczności i najbliższa opozycja. */
    public function present(array $data): array
    {
        $ts    = strtotime($data['date'] . ' 12:00 UTC');
        $pos   = $data['positions'];
        $earth = $pos['Ziemia'];

        // Ziemia z przodu kadru (−90° po obrocie), lekko w prawo — podpis „1 au” jest z przodu po lewej
        $azimuth = -75 - rad2deg(atan2($earth[1], $earth[0]));

        $planets = array_map(fn($name) => [
            'label'  => $name,
            'radius' => self::ELEMENTS[$name][0][0],
            'angle'  => rad2deg(atan2($pos[$name][1], $pos[$name][0])),
            'pos'    => $pos[$name],
            'orbit'  => array_map(fn($m) => array_map(fn($v) => round($v, 4), self::orbitPoint($name, $ts, $m)), range(0, 360, 4)),
            'color'  => self::LOOK[$name][0],
            'size'   => self::LOOK[$name][1],
            'labeled' => true,
        ], array_keys(self::ELEMENTS));

        $rows = [];
        foreach (array_keys(self::ELEMENTS) as $name) {
            if ($name === 'Ziemia') {
                continue;
            }
            $dist   = sqrt(array_sum(array_map(fn($a, $b) => ($a - $b) ** 2, $pos[$name], $earth)));
            $rows[] = ['cells' => [$name, $this->km($dist), $this->sky($name, self::elongation($name, $ts))], 'color' => self::LOOK[$name][0]];
        }

        return [
            'dateText'  => $this->dateText($data['date']),
            'event'     => $this->nextOpposition($data['oppositions'], $data['date']),
            'azimuth'   => round($azimuth, 1),
            'planets'   => $planets,
            'rows'      => $rows,
        ] + $data;
    }

    /** 0,52 au → „78 mln km”, 28,9 au → „4,32 mld km” */
    private function km(float $au): string
    {
        $km = $au * self::AU_KM;
        return $km >= 1e9
            ? number_format($km / 1e9, 2, ',', ' ') . ' mld km'
            : number_format($km / 1e6, 0, ',', ' ') . ' mln km';
    }

    /** Gdzie szukać planety: blisko Słońca jej nie widać, na wschód od Słońca — wieczorem, na zachód — rano. */
    private function sky(string $name, float $elongation): string
    {
        $abs = abs($elongation);
        return match (true) {
            $abs < ($name === 'Merkury' ? 18 : 15) => 'przy Słońcu',
            $abs > 150                              => 'całą noc',
            $elongation > 0                         => 'wieczorem',
            default                                 => 'rano',
        };
    }

    /** Najbliższa z opozycji Marsa, Jowisza i Saturna — kiedy planeta jest najbliżej i najjaśniejsza. */
    private function nextOpposition(array $oppositions, string $today): string
    {
        if (!$oppositions) {
            return '';
        }
        asort($oppositions);
        $name = array_key_first($oppositions);
        $days = (int) round((strtotime($oppositions[$name]) - strtotime($today)) / 86400);
        $when = match (true) {
            $days === 0 => 'dziś',
            $days === 1 => 'jutro',
            default     => 'za ' . $days . ' dni (' . date('d.m.Y', strtotime($oppositions[$name])) . ')',
        };
        return "{$name} w opozycji {$when} — najbliżej Ziemi i najjaśniejszy w roku";
    }

    private function dateText(string $date): string
    {
        $months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        [$y, $m, $d] = explode('-', $date);
        return (int) $d . ' ' . $months[(int) $m - 1] . ' ' . $y;
    }
}
