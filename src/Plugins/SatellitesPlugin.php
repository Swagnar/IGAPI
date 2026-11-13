<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use DateTimeImmutable;
use RuntimeException;

/**
 * Działające satelity na orbicie z katalogu SATCAT (CelesTrak, grupa „active”): ile ich jest, jaka część to Starlink,
 * ile przybyło w miesiąc, ile jest polskich, w którym roku wystartowały (wykres) i czyje są.
 * Plik ma ~6 MB, ale do cache trafiają tylko policzone sumy.
 */
class SatellitesPlugin extends AbstractPlugin
{
    private const SATCAT_URL = 'https://celestrak.org/satcat/records.php?GROUP=active&FORMAT=json';

    /** Wykres od tego roku — wcześniejsze działające satelity to pojedyncze sztuki */
    private const FROM_YEAR = 2000;

    /** Największe sieci satelitów: początek nazwy w SATCAT → nazwa po polsku */
    private const CONSTELLATIONS = [
        'STARLINK' => 'Starlink', 'ONEWEB' => 'OneWeb', 'KUIPER' => 'Kuiper (Amazon)',
        'QIANFAN' => 'Qianfan (Chiny)', 'HULIANWANG' => 'Guowang (Chiny)', 'IRIDIUM' => 'Iridium', 'FLOCK' => 'Flock (Planet)',
    ];

    /** Kody właścicieli SATCAT → nazwy po polsku */
    private const OWNERS = [
        'US' => 'USA', 'PRC' => 'Chiny', 'UK' => 'Wielka Brytania', 'CIS' => 'Rosja', 'JPN' => 'Japonia', 'IT' => 'Włochy',
        'FR' => 'Francja', 'GER' => 'Niemcy', 'IND' => 'Indie', 'SKOR' => 'Korea Płd.', 'ESA' => 'ESA', 'POL' => 'Polska',
        'CA' => 'Kanada', 'FIN' => 'Finlandia', 'LUXE' => 'Luksemburg', 'SPN' => 'Hiszpania', 'ISRA' => 'Izrael',
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("SatellitesPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Satelity na orbicie'; }

    public function getCredit(): string { return 'CelesTrak'; }

    public function fetchData(): array
    {
        Console::plugin("SatellitesPlugin: pobieram katalog działających satelitów z CelesTrak...");
        $objects = $this->http->get(self::SATCAT_URL);
        if (count($objects) < 1000) {
            throw new RuntimeException('CelesTrak SATCAT: podejrzanie mało obiektów (' . count($objects) . ')');
        }

        $since          = (new DateTimeImmutable('-30 days'))->format('Y-m-d');
        $years          = [];
        $owners         = [];
        $constellations = array_fill_keys(array_keys(self::CONSTELLATIONS), 0);
        $lastMonth      = 0;
        $polish         = [];
        foreach ($objects as $o) {
            $launch = (string) ($o['LAUNCH_DATE'] ?? '');
            $year   = (int) substr($launch, 0, 4);
            $years[$year]  = ($years[$year] ?? 0) + 1;
            $owner         = (string) ($o['OWNER'] ?? '');
            $owners[$owner] = ($owners[$owner] ?? 0) + 1;
            $lastMonth     += $launch >= $since ? 1 : 0;

            $name = strtoupper((string) ($o['OBJECT_NAME'] ?? ''));
            foreach (array_keys(self::CONSTELLATIONS) as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $constellations[$prefix]++;
                    break;
                }
            }
            if ($owner === 'POL') {
                $polish[] = ['name' => (string) $o['OBJECT_NAME'], 'launch' => $launch];
            }
        }
        ksort($years);
        arsort($owners);
        arsort($constellations);
        usort($polish, fn($a, $b) => strcmp($b['launch'], $a['launch']));

        Console::success('SatellitesPlugin: ' . count($objects) . " działających satelitów, w 30 dni +{$lastMonth}");
        return [
            'total'          => count($objects),
            'lastMonth'      => $lastMonth,
            'years'          => $years,
            'owners'         => array_slice($owners, 0, 8, true),
            'constellations' => $constellations,
            'polish'         => $polish,
        ];
    }

    /** Pola dla plugins/satellites.json: liczba, udział Starlinka, przyrost, polskie, słupki lat i tabele. */
    public function present(array $data): array
    {
        $num      = fn(int $v) => number_format($v, 0, ',', ' ');
        $starlink = $data['constellations']['STARLINK'] ?? 0;
        $share    = $data['total'] ? $starlink / $data['total'] : 0;
        $year     = (int) date('Y');

        $bars = [];
        for ($y = self::FROM_YEAR; $y <= $year; $y++) {
            $bars[] = ['label' => $y === $year ? "{$y}*" : (string) $y, 'value' => $data['years'][$y] ?? 0, 'tick' => $y % 5 === 0];
        }

        $polish = $data['polish'];
        $latest = $polish[0] ?? null;

        return [
            'totalText'    => $num($data['total']),
            'starlinkLine' => sprintf('działających satelitów · %s z nich (%d%%) to Starlink', $num($starlink), (int) round($share * 100)),
            'growthLine'   => 'W ostatnie 30 dni przybyło ' . $num($data['lastMonth'])
                . ($polish ? ' · polskich: ' . count($polish) . ($latest ? ' (ostatni: ' . $latest['name'] . ', ' . date('d.m.Y', strtotime($latest['launch'])) . ')' : '') : ''),
            'bars'         => $bars,
            'chartNote'    => 'Rok startu satelitów, które wciąż działają · *' . $year . ' do dziś',
            'networkRows'  => array_values(array_map(fn($prefix, $count) => ['cells' => [self::CONSTELLATIONS[$prefix], $num($count)]],
                array_keys(array_slice(array_filter($data['constellations']), 0, 5, true)),
                array_slice(array_filter($data['constellations']), 0, 5, true))),
            'ownerRows'    => array_values(array_map(fn($code, $count) => ['cells' => [self::OWNERS[$code] ?? $code, $num($count)]],
                array_keys(array_slice($data['owners'], 0, 5, true)),
                array_slice($data['owners'], 0, 5, true))),
        ] + $data;
    }
}
