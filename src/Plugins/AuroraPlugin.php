<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Szansa na zorzę polarną nad Polską w nadchodzącą noc: najwyższy prognozowany indeks Kp (NOAA SWPC)
 * między zmierzchem a świtem. Nie ma własnego bloku — przy Kp ≥ MIN_KP dokłada poświatę na mapę pogody
 * (pole `glow` dla HeatMap) i jedną linię tekstu; przy słabszej aktywności oba pola są puste.
 * Plugin nie używa skip(), bo pominąłby całą pogodę, do której jest doklejony.
 */
class AuroraPlugin extends AbstractPlugin
{
    private const KP_URL   = 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index-forecast.json';
    private const TIMEZONE = 'Europe/Warsaw';
    private const MIN_KP   = 5;

    /** Noc = przedziały 3-godzinne zaczynające się od 16:00 do 04:00 czasu polskiego */
    private const NIGHT_FROM = 16;
    private const NIGHT_TO   = 4;

    /**
     * Kp → szerokość geograficzna, z której zorzę widać nisko nad północnym horyzontem (dla ~20°E).
     * Przybliżenie z map NOAA: każdy stopień Kp to ok. 2° na południe.
     */
    private const VIEW_LAT = [5 => 54.5, 6 => 52.5, 7 => 50.5, 8 => 48.5, 9 => 46.5];

    /** Najdalej na południe, dokąd sięga widoczność: [szerokość, opis] — od północy, po szerokościach miast */
    private const REACH = [
        [54.35, 'tylko nad samym morzem'],
        [53.43, 'aż po Gdańsk'],
        [53.12, 'aż po Szczecin i Olsztyn'],
        [52.23, 'aż po Bydgoszcz'],
        [51.76, 'aż po Poznań i Warszawę'],
        [51.11, 'aż po Łódź'],
        [50.06, 'aż po Wrocław i Lublin'],
        [-90,   'w całej Polsce'],
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("AuroraPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Zorza nad Polską'; }

    public function getCredit(): string { return 'NOAA'; }

    public function fetchData(): array
    {
        Console::plugin("AuroraPlugin: pobieram prognozę Kp z NOAA SWPC...");
        $rows = $this->http->get(self::KP_URL);

        $tz    = new DateTimeZone(self::TIMEZONE);
        $now   = new DateTimeImmutable('now', $tz);
        $from  = $now->setTime(self::NIGHT_FROM, 0);
        $to    = $now->modify('+1 day')->setTime(self::NIGHT_TO, 0);
        $night = [];
        foreach ($rows as $row) {
            // Starszy format: tablica tablic z nagłówkiem; nowszy: obiekty z time_tag / kp
            $tag = $row['time_tag'] ?? $row[0] ?? null;
            $kp  = $row['kp'] ?? $row['Kp'] ?? $row[1] ?? null;
            if (!is_string($tag) || !is_numeric($kp)) {
                continue;
            }
            $at = (new DateTimeImmutable($tag, new DateTimeZone('UTC')))->setTimezone($tz);
            if ($at >= $from && $at <= $to) {
                $night[] = ['at' => $at->getTimestamp(), 'kp' => (float) $kp];
            }
        }
        if (!$night) {
            throw new \RuntimeException('NOAA SWPC: brak prognozy Kp na nadchodzącą noc');
        }

        $max = array_reduce($night, fn($a, $r) => $a === null || $r['kp'] > $a['kp'] ? $r : $a);
        Console::success(sprintf('AuroraPlugin: maks. Kp %.2f o %s', $max['kp'], date('H:i', $max['at'])));
        return ['kpMax' => $max['kp'], 'at' => $max['at'], 'night' => $night];
    }

    /** Pola dla mapy pogody: glow (poświata dla HeatMap) i text — oba null, gdy zorzy nie będzie widać. */
    public function present(array $data): array
    {
        $kp = (float) $data['kpMax'];
        if ($kp < self::MIN_KP) {
            return ['glow' => null, 'text' => null] + $data;
        }

        $lat   = $this->viewLatitude($kp);
        $where = 'w całej Polsce';
        foreach (self::REACH as [$limit, $label]) {
            if ($lat > $limit) {
                $where = $label;
                break;
            }
        }
        $hour = (new DateTimeImmutable('@' . $data['at']))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('H');

        return [
            'glow' => ['lat' => $lat, 'strength' => min(1, 0.55 + ($kp - self::MIN_KP) * 0.15)],
            'text' => sprintf('Zorza polarna w nocy · Kp %s, najsilniej ok. %d:00 · widać ją %s', number_format($kp, 1, ',', ''), (int) $hour, $where),
        ] + $data;
    }

    /** Przy Kp poniżej progu zorzy nie będzie widać — pogoda rysuje się bez poświaty, a WWW pokazuje „brak danych”. */
    public function hasContent(array $presented): bool
    {
        return ($presented['text'] ?? null) !== null;
    }

    /** Liniowo między progami VIEW_LAT (Kp 5,7 → 53,1°). */
    private function viewLatitude(float $kp): float
    {
        $kp = min(9, $kp);
        $lo = (int) floor($kp);
        $hi = min(9, $lo + 1);
        return round(self::VIEW_LAT[$lo] + (self::VIEW_LAT[$hi] - self::VIEW_LAT[$lo]) * ($kp - $lo), 2);
    }
}
