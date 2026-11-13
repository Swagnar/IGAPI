<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Kolejki do lekarza: ile średnio czeka się na wizytę u specjalisty na NFZ (przypadek stabilny) w województwach —
 * mediana z poradni, które podają czas oczekiwania (NFZ Terminy Leczenia, statystyki poradni aktualizowane co miesiąc).
 * Specjalność zmienia się codziennie. Pełna lista poradni to ~30 stron API, więc wynik dla specjalności trzymamy
 * tydzień w cache/nfz/. Wygląd — plugins/doctors.json (1/4).
 */
class DoctorsPlugin extends AbstractPlugin
{
    private const URL = 'https://api.nfz.gov.pl/app-itl-api/queues';
    private const DIR = ROOT_DIR . '/cache/nfz';

    /** Lista poradni dla specjalności jest świeża przez tydzień — statystyki NFZ zmieniają się raz w miesiącu */
    private const KEEP_DAYS = 7;

    /** Świadczenie NFZ → kogo szukamy (w mianowniku i dopełniaczu do tekstów) */
    private const SPECIALTIES = [
        'ŚWIADCZENIA Z ZAKRESU ORTOPEDII I TRAUMATOLOGII NARZĄDU RUCHU' => ['ortopeda', 'ortopedy'],
        'ŚWIADCZENIA Z ZAKRESU ENDOKRYNOLOGII'                          => ['endokrynolog', 'endokrynologa'],
        'ŚWIADCZENIA Z ZAKRESU NEUROLOGII'                              => ['neurolog', 'neurologa'],
        'ŚWIADCZENIA Z ZAKRESU OKULISTYKI'                              => ['okulista', 'okulisty'],
        'PORADNIA DERMATOLOGICZNA'                                      => ['dermatolog', 'dermatologa'],
        'PORADNIA ALERGOLOGICZNA'                                       => ['alergolog', 'alergologa'],
        'PORADNIA REUMATOLOGICZNA'                                      => ['reumatolog', 'reumatologa'],
        'ŚWIADCZENIA Z ZAKRESU UROLOGII'                                => ['urolog', 'urologa'],
        'ŚWIADCZENIA Z ZAKRESU OTOLARYNGOLOGII'                         => ['laryngolog', 'laryngologa'],
        'ŚWIADCZENIA Z ZAKRESU GASTROENTEROLOGII'                       => ['gastroenterolog', 'gastroenterologa'],
        'ŚWIADCZENIA Z ZAKRESU DIABETOLOGII'                            => ['diabetolog', 'diabetologa'],
        'ŚWIADCZENIA Z ZAKRESU GRUŹLICY I CHORÓB PŁUC'                  => ['pulmonolog', 'pulmonologa'],
        'ŚWIADCZENIA Z ZAKRESU NEFROLOGII'                              => ['nefrolog', 'nefrologa'],
        'ŚWIADCZENIA Z ZAKRESU ONKOLOGII'                               => ['onkolog', 'onkologa'],
        'PORADNIA CHIRURGII OGÓLNEJ'                                    => ['chirurg', 'chirurga'],
    ];

    /** Województwo (2 pierwsze cyfry TERYT) → [nazwa, lon, lat środka] */
    private const PROVINCES = [
        '02' => ['dolnośląskie', 16.4, 51.1], '04' => ['kujawsko-pomorskie', 18.5, 53.1], '06' => ['lubelskie', 22.9, 51.2],
        '08' => ['lubuskie', 15.3, 52.2], '10' => ['łódzkie', 19.4, 51.6], '12' => ['małopolskie', 20.3, 49.9],
        '14' => ['mazowieckie', 21.1, 52.5], '16' => ['opolskie', 17.9, 50.6], '18' => ['podkarpackie', 22.2, 50.0],
        '20' => ['podlaskie', 22.9, 53.3], '22' => ['pomorskie', 17.9, 54.2], '24' => ['śląskie', 19.0, 50.4],
        '26' => ['świętokrzyskie', 20.8, 50.8], '28' => ['warmińsko-mazurskie', 20.8, 53.8], '30' => ['wielkopolskie', 17.2, 52.3],
        '32' => ['zachodniopomorskie', 15.5, 53.6],
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("DoctorsPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Kolejki do lekarza'; }

    public function getCredit(): string { return 'NFZ'; }

    public function fetchData(): array
    {
        // Specjalność dnia — ta sama przez cały dzień
        $benefits = array_keys(self::SPECIALTIES);
        $benefit  = $benefits[(int) date('z') % count($benefits)];
        Console::plugin("DoctorsPlugin: kolejki NFZ — {$benefit}...");

        $clinics = $this->clinics($benefit);

        // Województwo → czasy oczekiwania poradni i liczba czekających
        $provinces = [];
        foreach ($clinics as [$teryt, $days, $awaiting]) {
            $code = substr($teryt, 0, 2);
            if (isset(self::PROVINCES[$code])) {
                $provinces[$code]['days'][]   = $days;
                $provinces[$code]['awaiting'] = ($provinces[$code]['awaiting'] ?? 0) + $awaiting;
            }
        }
        $result = [];
        foreach ($provinces as $code => $p) {
            $result[$code] = ['median' => $this->median($p['days']), 'clinics' => count($p['days']), 'awaiting' => $p['awaiting']];
        }
        if (count($result) < 12) {
            throw new RuntimeException('NFZ: dane czasu oczekiwania tylko dla ' . count($result) . ' województw');
        }

        $all = array_column($clinics, 1);
        Console::success(sprintf('DoctorsPlugin: %d poradni, mediana %d dni', count($all), $this->median($all)));
        return [
            'benefit'   => $benefit,
            'median'    => $this->median($all),
            'awaiting'  => array_sum(array_column($clinics, 2)),
            'provinces' => $result,
        ];
    }

    /** Pola dla plugins/doctors.json: kto, mediana kraju, skrajne województwa, punkty mapy. */
    public function present(array $data): array
    {
        [$who, $whom] = self::SPECIALTIES[$data['benefit']] ?? ['specjalista', 'specjalisty'];
        $provinces    = $data['provinces'];
        uasort($provinces, fn($a, $b) => $b['median'] <=> $a['median']);
        $longest  = array_key_first($provinces);
        $shortest = array_key_last($provinces);

        $points = [];
        foreach ($provinces as $code => $p) {
            [$name, $lon, $lat] = self::PROVINCES[$code];
            $label    = $code === $longest || $code === $shortest;
            $points[] = [
                'lon'      => $lon,
                'lat'      => $lat,
                'value'    => $p['median'],
                'label'    => $label ? $this->days($p['median']) : '',
                'sub'      => $label ? $name : null,
                'priority' => $label,
            ];
        }

        return [
            'whoText'      => 'Do ' . $whom . ' na NFZ',
            'medianText'   => $this->days($data['median']),
            'awaitingText' => sprintf('w kolejce %s osób', number_format($data['awaiting'], 0, ',', ' ')),
            'rangeText'    => sprintf('Najdłużej: %s, %s · najkrócej: %s, %s',
                self::PROVINCES[$longest][0], $this->days($provinces[$longest]['median']),
                self::PROVINCES[$shortest][0], $this->days($provinces[$shortest]['median'])),
            'points'       => $points,
            'palette'      => [[10, [74, 222, 128], 70], [30, [250, 204, 21], 65], [60, [249, 115, 22], 60], [120, [239, 68, 68], 55]],
            'who'          => $who,
        ] + $data;
    }

    /**
     * Poradnie dla świadczenia: [TERYT, średni czas oczekiwania w dniach, liczba czekających] — tylko te, które
     * podają czas albo mają kolejkę. Z pliku w cache/nfz/, jeśli ma mniej niż tydzień.
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function clinics(string $benefit): array
    {
        $file = self::DIR . '/' . md5($benefit) . '.json';
        if (is_file($file) && filemtime($file) > time() - self::KEEP_DAYS * 86400) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && $cached) {
                return $cached;
            }
        }

        $clinics = [];
        for ($page = 1; $page <= 60; $page++) {
            $response = $this->http->get(self::URL . '?' . http_build_query([
                'page' => $page, 'limit' => 25, 'format' => 'json', 'case' => 1, 'benefit' => $benefit, 'api-version' => '1.3',
            ]));
            foreach ($response['data'] ?? [] as $item) {
                $a     = $item['attributes'] ?? [];
                $stats = $a['statistics']['provider-data'] ?? [];
                $days  = (int) ($stats['average-period'] ?? 0);
                $wait  = (int) ($stats['awaiting'] ?? 0);
                if (($days > 0 || $wait > 0) && !empty($a['teryt-place'])) {
                    $clinics[] = [(string) $a['teryt-place'], $days, $wait];
                }
            }
            if (empty($response['links']['next'])) {
                break;
            }
        }
        if (!$clinics) {
            throw new RuntimeException("NFZ: brak poradni z czasem oczekiwania dla {$benefit}");
        }

        @mkdir(self::DIR, 0775, true);
        file_put_contents($file, json_encode($clinics));
        return $clinics;
    }

    private function median(array $values): int
    {
        sort($values);
        $n = count($values);
        return $n === 0 ? 0 : (int) round($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }

    private function days(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'dzień' : 'dni');
    }
}
