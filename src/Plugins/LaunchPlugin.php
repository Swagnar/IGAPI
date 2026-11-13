<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;
use Swagnar\Igapi\Http\JsonClient;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Najbliższy start rakiety z Launch Library 2 (The Space Devs): rakieta, misja, operator, miejsce, godzina
 * po polsku i zdjęcie — plus kilka kolejnych startów. Limit API (~15 zapytań/h) z dziennym cache nie przeszkadza.
 */
class LaunchPlugin extends AbstractPlugin
{
    private const API_URL  = 'https://ll.thespacedevs.com/2.3.0/launches/upcoming/?limit=10&mode=normal&hide_recent_previous=true';
    private const TIMEZONE = 'Europe/Warsaw';
    private const NEXT     = 3;   // kolejne starty pod głównym

    /** Statusy z pewną datą: Go, To Be Confirmed. TBD to zwykle tylko „gdzieś w tym miesiącu” */
    private const CERTAIN = ['Go', 'TBC'];

    private const COUNTRIES = [
        'USA' => 'USA', 'CHN' => 'Chiny', 'RUS' => 'Rosja', 'KAZ' => 'Kazachstan', 'IND' => 'Indie',
        'JPN' => 'Japonia', 'GUF' => 'Gujana Francuska', 'FRA' => 'Francja', 'NZL' => 'Nowa Zelandia',
        'KOR' => 'Korea Płd.', 'PRK' => 'Korea Płn.', 'IRN' => 'Iran', 'ISR' => 'Izrael', 'GBR' => 'Wielka Brytania',
        'NOR' => 'Norwegia', 'SWE' => 'Szwecja', 'AUS' => 'Australia', 'BRA' => 'Brazylia', 'MHL' => 'Wyspy Marshalla',
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("LaunchPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Najbliższy start rakiety'; }

    public function getCredit(): string { return 'Launch Library 2'; }

    public function fetchData(): array
    {
        Console::plugin("LaunchPlugin: pobieram starty z Launch Library 2...");
        $results = $this->http->get(self::API_URL)['results'] ?? [];

        $now      = time();
        $launches = [];
        foreach ($results as $r) {
            $net = strtotime((string) ($r['net'] ?? ''));
            if ($net === false || $net < $now || !in_array($r['status']['abbrev'] ?? '', self::CERTAIN, true)) {
                continue;
            }
            $launches[] = [
                'name'      => (string) $r['name'],
                'rocket'    => (string) ($r['rocket']['configuration']['full_name'] ?? $r['rocket']['configuration']['name'] ?? '?'),
                'mission'   => (string) ($r['mission']['name'] ?? ''),
                'orbit'     => $r['mission']['orbit']['abbrev'] ?? null,
                'provider'  => (string) ($r['launch_service_provider']['name'] ?? ''),
                'net'       => $net,
                'precision' => (string) ($r['net_precision']['name'] ?? ''),
                'location'  => (string) ($r['pad']['location']['name'] ?? ''),
                'country'   => (string) ($r['pad']['location']['country']['alpha_3_code'] ?? ''),
                'image'     => $r['image']['image_url'] ?? null,
            ];
        }
        if (!$launches) {
            throw new RuntimeException('Launch Library 2: brak zaplanowanych startów z pewną datą');
        }

        // Opis tylko dla pierwszego — tłumaczenie trwa
        $first = $results[array_search($launches[0]['name'], array_column($results, 'name'), true)];
        $about = trim((string) ($first['mission']['description'] ?? ''));
        $launches[0]['description'] = $about !== ''
            ? GdImageHelper::translateToPolish(GdImageHelper::truncateAtWordBoundary($about, 220))
            : null;

        Console::success("LaunchPlugin: {$launches[0]['name']} — " . date('d.m H:i', $launches[0]['net']));
        return ['launches' => array_slice($launches, 0, self::NEXT + 1), 'fetched' => $now];
    }

    /** Pola dla plugins/launch.json: rakieta, misja, kiedy (z odliczaniem od pobrania), skąd, kolejne starty. */
    public function present(array $data): array
    {
        $next = $data['launches'][0];
        return [
            'rocket'      => $next['rocket'],
            'missionLine' => implode(' · ', array_filter([$next['mission'], $next['provider']])),
            'whenText'    => $this->when($next, $data['fetched']),
            'placeText'   => $this->place($next),
            'image'       => $next['image'],
            'description' => $next['description'] ?? null,
            'nextRows'    => array_map(fn($l) => ['cells' => [
                $this->localDate($l['net'], $l['precision'] === 'Minute' ? 'd.m H:i' : 'd.m'),
                $l['rocket'],
                self::COUNTRIES[$l['country']] ?? '',
            ]], array_slice($data['launches'], 1)),
        ] + $data;
    }

    /** „jutro o 03:49 · za 21 h”, przy dokładności do dnia sama data. */
    private function when(array $launch, int $from): string
    {
        $tz   = new DateTimeZone(self::TIMEZONE);
        $at   = (new DateTimeImmutable('@' . $launch['net']))->setTimezone($tz);
        $base = (new DateTimeImmutable('@' . $from))->setTimezone($tz);
        $days = (int) $base->setTime(0, 0)->diff($at->setTime(0, 0))->format('%r%a');
        $day  = match ($days) {
            0       => 'dziś',
            1       => 'jutro',
            2       => 'pojutrze',
            default => $at->format('d.m'),
        };
        if (!in_array($launch['precision'], ['Minute', 'Second', 'Hour'], true)) {
            return $day;
        }

        $hours = (int) round(($launch['net'] - $from) / 3600);
        $left  = $hours < 48 ? "za {$hours} h" : 'za ' . intdiv($hours + 12, 24) . ' dni';
        return ($launch['precision'] === 'Hour' ? "{$day} ok. " : "{$day} o ") . $at->format('H:i') . " · {$left}";
    }

    /** „Vandenberg SFB, CA, USA” → „Vandenberg SFB · USA” — kraj po polsku, bez skrótów stanów. */
    private function place(array $launch): string
    {
        $site    = trim(explode(',', $launch['location'])[0]);
        $country = self::COUNTRIES[$launch['country']] ?? null;
        return $country !== null && $site !== $country ? "{$site} · {$country}" : $site;
    }

    private function localDate(int $ts, string $format): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(self::TIMEZONE))->format($format);
    }
}
