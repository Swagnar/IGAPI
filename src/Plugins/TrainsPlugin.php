<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Opóźnienia pociągów wczoraj: punktualność na stacji końcowej, najbardziej opóźniony kurs z trasą na mapie
 * i średnie opóźnienie przyjazdów na większych stacjach (PKP PLK, Otwarte Dane Kolejowe).
 * /operations zwraca wykonanie z trzech ostatnich dni — bierzemy kursy z wczorajszą datą kursowania.
 * Klucz PLK_API_KEY (pakiet Basic: 100 zapytań/h) — tu ~12 zapytań. Wygląd — plugins/trains.json (1/4).
 */
class TrainsPlugin extends AbstractPlugin
{
    private const API = 'https://pdp-api.plk-sa.pl/api/v1';
    private const DIR = ROOT_DIR . '/cache/plk';

    /** Słownik stacji (nazwy i współrzędne) zmienia się rzadko */
    private const STATIONS_DAYS = 30;

    /** Strona /operations: 2500 kursów to ~70 MB po zdekodowaniu — mieści się w limicie pamięci hostingu */
    private const PAGE_SIZE = 2500;

    /** Na czas = do 5 min 59 s spóźnienia na stacji końcowej (definicja UTK) */
    private const PUNCTUAL = 5;

    /** Stacja na mapie, jeśli wczoraj przyjechało na nią co najmniej tyle pociągów */
    private const MIN_ARRIVALS = 60;

    /** Ilu najbardziej opóźnionych kursów szukać w rozkładzie (nazwa, numer) */
    private const TOP = 3;

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("TrainsPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Opóźnienia pociągów'; }

    public function getCredit(): string { return 'PKP PLK'; }

    public function fetchData(): array
    {
        $date = date('Y-m-d', strtotime('-1 day'));
        Console::plugin("TrainsPlugin: wykonanie pociągów z {$date}...");
        $stations = $this->stations();

        $completed = $punctual = $delaySum = $cancelled = 0;
        $arrivals  = [];   // stacja → [suma opóźnień, liczba przyjazdów]
        $worst     = [];   // [opóźnienie, sid, oid, stacje trasy]
        for ($page = 1; $page <= 20; $page++) {
            $response = $this->get('/operations/shortened', ['page' => $page, 'pageSize' => self::PAGE_SIZE, 'withPlanned' => 'true']);
            foreach ($response['tr'] ?? [] as $train) {
                if (($train['od'] ?? '') !== $date) {
                    continue;
                }
                if ($train['s'] === 'X') {
                    $cancelled++;
                    continue;
                }
                $final = null;
                foreach ($train['st'] ?? [] as $stop) {
                    if (isset($stop['adm'], $stop['aa'])) {
                        $arrivals[$stop['id']][0] = ($arrivals[$stop['id']][0] ?? 0) + $stop['adm'];
                        $arrivals[$stop['id']][1] = ($arrivals[$stop['id']][1] ?? 0) + 1;
                        $final = $stop['adm'];
                    }
                }
                // Punktualność tylko z kursów zakończonych — przyjazd na ostatnią stację z pomiarem
                if ($train['s'] !== 'C' || $final === null) {
                    continue;
                }
                $completed++;
                $delaySum += max(0, $final);
                $punctual += $final <= self::PUNCTUAL ? 1 : 0;
                // Do rankingu tylko kursy z co najmniej dwiema stacjami w Polsce — pociąg zza granicy
                // (np. z Ukrainy do Przemyśla) przywozi spóźnienie, którego nie widać na polskiej trasie
                $route = array_values(array_filter(array_column($train['st'], 'id'), fn($id) => isset($stations[$id])));
                if (count(array_unique($route)) >= 2) {
                    $worst[] = [$final, $train['sid'], $train['oid'], $route];
                }
                if (count($worst) > 50) {
                    usort($worst, fn($a, $b) => $b[0] <=> $a[0]);
                    $worst = array_slice($worst, 0, self::TOP);
                }
            }
            $more = $response['pg']['hn'] ?? false;
            unset($response);
            if (!$more) {
                break;
            }
        }
        if ($completed < 1000) {
            throw new RuntimeException("PLK: tylko {$completed} zakończonych kursów z {$date}");
        }

        usort($worst, fn($a, $b) => $b[0] <=> $a[0]);
        $top = [];
        foreach (array_slice($worst, 0, self::TOP) as [$delay, $sid, $oid, $route]) {
            $info  = $this->get("/schedules/route/{$sid}/{$oid}");
            $top[] = [
                'delay'  => $delay,
                'number' => trim(($info['commercialCategorySymbol'] ?? '') . ' ' . ($info['nationalNumber'] ?? '')),
                'name'   => trim((string) ($info['name'] ?? '')),
                'from'   => $stations[$route[0]][0],
                'to'     => $stations[end($route)][0],
                'route'  => array_map(fn($id) => [$stations[$id][1], $stations[$id][2]], $route),
            ];
        }

        $busy = [];
        foreach ($arrivals as $id => [$sum, $count]) {
            if ($count >= self::MIN_ARRIVALS && isset($stations[$id])) {
                $busy[] = [$stations[$id][0], $stations[$id][1], $stations[$id][2], round($sum / $count, 1), $count];
            }
        }

        Console::success(sprintf('TrainsPlugin: %d kursów, %.1f%% na czas, najdłużej +%d min', $completed, 100 * $punctual / $completed, $top[0]['delay'] ?? 0));
        return [
            'date'      => $date,
            'completed' => $completed,
            'punctual'  => $punctual,
            'avgDelay'  => round($delaySum / $completed, 1),
            'cancelled' => $cancelled,
            'top'       => $top,
            'stations'  => $busy,
        ];
    }

    /** Pola dla plugins/trains.json: punktualność, najgorszy kurs, mapa stacji z jego trasą. */
    public function present(array $data): array
    {
        $share = 100 * $data['punctual'] / max(1, $data['completed']);
        $top   = $data['top'][0] ?? null;

        // Ciepło z regionów (siatka ~1° × 0,6°, średnia ważona liczbą przyjazdów) — 170 gęstych stacji
        // uśrednia się w IDW do jednego koloru. Bez podpisów — mapa ma być tłem dla trasy.
        $cells = [];
        foreach ($data['stations'] as [, $lon, $lat, $avg, $count]) {
            $key = floor($lon) . ':' . floor($lat / 0.6);
            $cells[$key][0] = ($cells[$key][0] ?? 0) + $lon * $count;
            $cells[$key][1] = ($cells[$key][1] ?? 0) + $lat * $count;
            $cells[$key][2] = ($cells[$key][2] ?? 0) + $avg * $count;
            $cells[$key][3] = ($cells[$key][3] ?? 0) + $count;
        }
        $points = [];
        foreach ($cells as [$lon, $lat, $sum, $count]) {
            $points[] = ['lon' => $lon / $count, 'lat' => $lat / $count, 'value' => $sum / $count, 'label' => ''];
        }

        $paths = [];
        if ($top && count($top['route']) > 1) {
            $paths[] = ['points' => array_map(fn($p) => [$p[0], $p[1], 1], $top['route']), 'color' => '#F87171', 'core' => '#FCA5A5'];
        }

        return [
            'shareText'  => number_format($share, 1, ',', ' ') . '% na czas',
            'shareColor' => $share >= 90 ? '#4ADE80' : ($share >= 80 ? '#FBBF24' : '#F87171'),
            'countText'  => sprintf('%s kursów · średnio %s min spóźnienia', number_format($data['completed'], 0, ',', ' '), number_format($data['avgDelay'], 1, ',', ' ')),
            'cancelText' => $data['cancelled'] ? sprintf('odwołanych: %d', $data['cancelled']) : 'bez odwołanych kursów',
            'worstText'  => $top ? sprintf('Najdłużej: %s%s %s → %s, +%s', $top['number'], $top['name'] !== '' ? ' „' . mb_convert_case($top['name'], MB_CASE_TITLE) . '”' : '',
                $top['from'], $top['to'], $this->duration($top['delay'])) : '',
            'points'     => $points,
            'paths'      => $paths,
            'palette'    => [[3, [74, 222, 128], 80], [4.5, [250, 204, 21], 75], [6, [249, 115, 22], 70], [8, [239, 68, 68], 65]],
        ] + $data;
    }

    /**
     * Stacje: id → [nazwa, lon, lat]. Z pliku w cache/plk/, jeśli ma mniej niż miesiąc.
     * @return array<int, array{0: string, 1: float, 2: float}>
     */
    private function stations(): array
    {
        $file = self::DIR . '/stations.json';
        if (is_file($file) && filemtime($file) > time() - self::STATIONS_DAYS * 86400) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && $cached) {
                return $cached;
            }
        }
        $stations = [];
        foreach ($this->get('/dictionaries/stations', ['pageSize' => 10000])['stations'] ?? [] as $s) {
            if (isset($s['latitude'], $s['longitude'])) {
                $stations[$s['id']] = [(string) $s['name'], (float) $s['longitude'], (float) $s['latitude']];
            }
        }
        if (count($stations) < 1000) {
            throw new RuntimeException('PLK: słownik stacji ma tylko ' . count($stations) . ' pozycji');
        }
        @mkdir(self::DIR, 0775, true);
        file_put_contents($file, json_encode($stations, JSON_UNESCAPED_UNICODE));
        return $stations;
    }

    private function get(string $path, array $query = []): array
    {
        $key = $_ENV['PLK_API_KEY'] ?? throw new RuntimeException('Brak PLK_API_KEY w .env');
        return $this->http->get(self::API . $path . ($query ? '?' . http_build_query($query) : ''), ['X-API-Key: ' . $key]);
    }

    private function duration(int $minutes): string
    {
        return $minutes >= 60 ? sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60) : "{$minutes} min";
    }
}
