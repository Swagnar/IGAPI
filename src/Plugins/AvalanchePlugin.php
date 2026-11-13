<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Lawiny w Tatrach: stopień zagrożenia lawinowego 1–5 z komunikatu TOPR (JSON osadzony w lawiny.topr.pl), zagrożone
 * wystawy powyżej granicy wysokości i prognoza na kolejne dni. Tylko w sezonie zimowym — poza nim albo bez ważnego
 * komunikatu plugin się pomija. Wygląd — plugins/avalanche.json (1/4).
 */
class AvalanchePlugin extends AbstractPlugin
{
    private const URL = 'https://lawiny.topr.pl/';

    /** Sezon: listopad–kwiecień (TOPR wydaje komunikaty, gdy w Tatrach leży śnieg) */
    private const SEASON = [11, 12, 1, 2, 3, 4];

    /** Stopień → [nazwa, kolor europejskiej skali] */
    private const LEVELS = [
        1 => ['niskie', '#4ADE80'], 2 => ['umiarkowane', '#FACC15'], 3 => ['znaczne', '#F97316'],
        4 => ['wysokie', '#EF4444'], 5 => ['bardzo wysokie', '#991B1B'],
    ];

    /** Kolejność bitów w polu "exp" komunikatu */
    private const DIRECTIONS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

    private const DAYS = ['nd', 'pn', 'wt', 'śr', 'cz', 'pt', 'sb'];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("AvalanchePlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Lawiny w Tatrach'; }

    public function getCredit(): string { return 'TOPR'; }

    public function canSkip(): bool { return true; }

    public function fetchData(): array
    {
        if (!in_array((int) date('n'), self::SEASON, true)) {
            return $this->skip('poza sezonem lawinowym');
        }
        Console::plugin("AvalanchePlugin: pobieram komunikat lawinowy TOPR...");

        $html = $this->http->getText(self::URL);
        if (!preg_match('/const oLawReport = (\{.*?\});\s*\n/s', $html, $m) || !is_array($report = json_decode($m[1], true))) {
            throw new RuntimeException('TOPR: nie znaleziono komunikatu lawinowego na stronie');
        }
        $level = (int) ($report['mst']['lev'] ?? 0);
        if ($level < 1 || strtotime((string) ($report['exp'] ?? '')) < time()) {
            return $this->skip('brak ważnego komunikatu lawinowego');
        }

        $upper = $report['am']['upper'] ?? [];
        Console::success(sprintf('AvalanchePlugin: stopień %d, ważny do %s', $level, $report['exp']));
        return [
            'level'    => $level,
            'name'     => (string) ($report['mst']['desc0'] ?? ''),
            'advice'   => (string) ($report['mst']['desc2'] ?? ''),
            'issued'   => (string) ($report['iat'] ?? ''),
            'height'   => (int) ($report['am']['height'] ?? 0),
            'exposure' => (string) ($upper['exp'] ?? ''),
            'history'  => array_map(fn($h) => ['date' => (string) $h['dat'], 'level' => (int) $h['lev']], $report['history'] ?? []),
        ];
    }

    /** Pola dla plugins/avalanche.json: stopień, opis, wystawy i kolejne dni. */
    public function present(array $data): array
    {
        $level = $data['level'];
        [$name, $color] = self::LEVELS[$level] ?? ['', '#AAB4CC'];

        // Wystawy zagrożone powyżej granicy wysokości
        $dirs     = array_values(array_filter(self::DIRECTIONS, fn($d, $i) => ($data['exposure'][$i] ?? '0') === '1', ARRAY_FILTER_USE_BOTH));
        $exposure = match (true) {
            !$dirs                      => '',
            count($dirs) === 8          => 'wszystkie wystawy',
            default                     => 'wystawy ' . implode(', ', $dirs),
        };

        // Kolejne dni z prognozy: „pt 2 ↑ · sb 2”
        $today = date('Y-m-d');
        $next  = [];
        $prev  = $level;
        foreach ($data['history'] as $h) {
            if ($h['date'] > $today && $h['level'] > 0) {
                $arrow  = $h['level'] > $prev ? ' ↑' : ($h['level'] < $prev ? ' ↓' : '');
                $next[] = self::DAYS[(int) date('w', strtotime($h['date']))] . ' ' . $h['level'] . $arrow;
                $prev   = $h['level'];
            }
        }

        return [
            'levelText'   => sprintf('Stopień %d — %s', $level, $name),
            'levelColor'  => $color,
            'adviceText'  => $data['advice'],
            'zoneText'    => trim(($data['height'] > 0 ? 'Powyżej ' . $data['height'] . ' m' : '') . ($exposure ? ': ' . $exposure : ''), ': '),
            'nextText'    => $next ? 'Dalej: ' . implode(' · ', $next) : '',
            'issuedText'  => $data['issued'] !== '' ? 'komunikat TOPR z ' . date('j.m, H:i', strtotime($data['issued'])) : 'komunikat TOPR',
            'zones'       => array_map(fn($l) => ['to' => $l + 0.5, 'color' => self::LEVELS[$l][1]], array_keys(self::LEVELS)),
        ] + $data;
    }
}
