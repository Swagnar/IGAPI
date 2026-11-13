<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Dane polskich gier na Steamie: szczyt graczy z ostatniej doby, dzienne szczyty z 30 dni, trend tydzień do tygodnia,
 * cena i miniaturka. Lista gier: data/polish_games.json. Wygląd: plugins/polishGames.json (lista — Layout\SteamList).
 *
 * Historia graczy: steamcharts.com/app/<id>/chart-data.json — punkty godzinowe z ostatniego miesiąca
 * (starsze miesięczne). Gdy jej nie ma — liczba graczy teraz z oficjalnego API Steama, bez wykresu.
 */
class PolishGamesPlugin extends AbstractPlugin
{
    private const CHART_URL     = 'https://steamcharts.com/app/%d/chart-data.json';
    private const STEAM_PLAYERS = 'https://api.steampowered.com/ISteamUserStats/GetNumberOfCurrentPlayers/v1/?appid=';
    private const STEAM_PRICES  = 'https://store.steampowered.com/api/appdetails?filters=price_overview&cc=pl&appids=';
    private const STEAM_BASIC   = 'https://store.steampowered.com/api/appdetails?filters=basic&appids=';
    private const GAMES_FILE    = ROOT_DIR . '/data/polish_games.json';
    private const KEPT          = 8;        // tyle gier trzymamy w cache — render pokazuje tyle, ile się zmieści
    private const DAYS          = 30;       // zakres wykresu
    private const PAUSE_US      = 700_000;  // grzecznie wobec steamcharts

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("PolishGamesPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Polskie gry na Steamie'; }

    public function getCredit(): string { return 'Steam / steamcharts.com'; }

    public function fetchData(): array
    {
        $games = json_decode((string) @file_get_contents(self::GAMES_FILE), true);
        if (!is_array($games) || $games === []) {
            throw new RuntimeException('Brak listy gier w data/polish_games.json');
        }

        Console::plugin('PolishGamesPlugin: pobieram historię graczy dla ' . count($games) . ' gier...');
        $prices = $this->prices(array_column($games, 'appid'));
        $result = [];
        foreach ($games as $game) {
            $appid = (int) $game['appid'];
            $stats = $this->history($appid);
            if ($stats === null) {
                $now   = $this->currentPlayers($appid);
                $stats = $now === null ? null : ['peak24' => $now, 'daily' => [], 'trend' => null, 'live' => true];
            }
            if ($stats !== null) {
                $result[] = $game + $stats + ['price' => $prices[$appid] ?? null];
            }
            usleep(self::PAUSE_US);
        }

        if ($result === []) {
            throw new RuntimeException('Nie udało się pobrać liczby graczy dla żadnej gry');
        }
        usort($result, fn($a, $b) => $b['peak24'] <=> $a['peak24']);
        $result = array_map(fn($g) => $g + ['thumb' => $this->thumbUrl((int) $g['appid'])], array_slice($result, 0, self::KEPT));
        Console::success('PolishGamesPlugin: ' . count($result) . ' gier');

        return ['games' => $result];
    }

    // ------------------------------------------------------------------------------------------ dane

    /**
     * Szczyt z ostatnich 24 h, dzienne szczyty z DAYS dni i trend: średnia ostatnich 7 dni vs 7 wcześniejszych.
     * @return array{peak24: int, daily: list<int>, trend: ?float, live: bool}|null
     */
    private function history(int $appid): ?array
    {
        $context = stream_context_create(['http' => [
            'timeout' => 20,
            'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36\r\n",
        ]]);
        $points = json_decode((string) @file_get_contents(sprintf(self::CHART_URL, $appid), false, $context), true);
        if (!is_array($points) || $points === []) {
            Console::warn("PolishGamesPlugin: steamcharts bez historii dla {$appid}");
            return null;
        }

        $now    = time();
        $today  = date('Y-m-d', $now);
        $peak24 = 0;
        $daily  = [];
        foreach ($points as [$ms, $players]) {
            $ts = intdiv((int) $ms, 1000);
            if ($ts >= $now - 86400) {
                $peak24 = max($peak24, (int) $players);
            }
            // Dzisiejszy dzień jest niepełny — zaniżałby koniec wykresu i trend
            if ($ts >= $now - (self::DAYS + 1) * 86400 && date('Y-m-d', $ts) !== $today) {
                $day         = date('Y-m-d', $ts);
                $daily[$day] = max($daily[$day] ?? 0, (int) $players);
            }
        }
        if ($peak24 === 0) {
            Console::warn("PolishGamesPlugin: brak świeżych danych z ostatniej doby dla {$appid}");
            return null;
        }

        ksort($daily);
        $daily = array_values($daily);
        $avg   = fn(array $v) => $v ? array_sum($v) / count($v) : 0;
        $last  = $avg(array_slice($daily, -7));
        $prev  = $avg(array_slice($daily, -14, 7));

        return [
            'peak24' => $peak24,
            'daily'  => $daily,
            'trend'  => count($daily) >= 14 && $prev > 0 ? round($last / $prev - 1, 3) : null,
            'live'   => false,
        ];
    }

    /** Adres miniaturki z appdetails (nowe gry mają w nim hash); null → SteamList użyje stałego adresu (starsze gry). */
    private function thumbUrl(int $appid): ?string
    {
        try {
            return $this->http->get(self::STEAM_BASIC . $appid)[$appid]['data']['capsule_image'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function currentPlayers(int $appid): ?int
    {
        try {
            return (int) ($this->http->get(self::STEAM_PLAYERS . $appid)['response']['player_count'] ?? 0) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ceny w zł jednym zapytaniem. Gra darmowa nie ma price_overview → 'free'.
     * @return array<int, array{final: string, discount: int}|array{free: true}>
     */
    private function prices(array $appids): array
    {
        try {
            $response = $this->http->get(self::STEAM_PRICES . implode(',', $appids));
        } catch (\Throwable $e) {
            Console::warn("PolishGamesPlugin: brak cen ze Steama ({$e->getMessage()})");
            return [];
        }

        $prices = [];
        foreach ($response as $appid => $entry) {
            if (!($entry['success'] ?? false)) {
                continue;
            }
            $price          = $entry['data']['price_overview'] ?? null;
            $prices[$appid] = $price === null
                ? ['free' => true]
                : ['final' => $price['final_formatted'], 'discount' => (int) $price['discount_percent']];
        }
        return $prices;
    }
}
