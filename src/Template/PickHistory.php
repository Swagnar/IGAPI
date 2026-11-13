<?php
namespace Swagnar\Igapi\Template;

/**
 * Co pule 'pick' wylosowały w poprzednich dniach — żeby ten sam blok nie wypadał kilka dni z rzędu.
 * Zapisuje tylko prawdziwy poranny run (TemplateModule::run), podgląd i edytor tylko czytają.
 *
 *   cache/picks.json: { "swiat": { "2026-11-12": { "body": ["earthquakes", "elNino"] } } }
 *
 * Blok w puli to id pluginu (węzeł Plugin) albo numer dziecka — id przetrwa dopisanie nowego pluginu do puli.
 */
final class PickHistory
{
    private const FILE = ROOT_DIR . '/cache/picks.json';

    /** Ile dni historii trzymać */
    private const KEEP = 14;

    /** Kara za niedawne wylosowanie: dni temu → mnożnik wagi */
    public const PENALTY = [1 => 0.15, 2 => 0.4];

    /** @return array<string, array<string, array<string, list<string>>>> slajd → data → pula → bloki */
    public static function load(): array
    {
        $log = json_decode((string) @file_get_contents(self::FILE), true);
        return is_array($log) ? $log : [];
    }

    /**
     * Niedawne losowania pul slajdu przed dniem $date: pula → blok → ile dni temu (najbliższy dzień wygrywa).
     * @param array<string, array<string, list<string>>> $days data → pula → bloki
     * @return array<string, array<string, int>>
     */
    public static function recent(array $days, string $date): array
    {
        $recent = [];
        foreach (array_keys(self::PENALTY) as $ago) {
            $day = date('Y-m-d', strtotime("{$date} -{$ago} day"));
            foreach ($days[$day] ?? [] as $pool => $ids) {
                foreach ($ids as $id) {
                    $recent[$pool][$id] ??= $ago;
                }
            }
        }
        return $recent;
    }

    /** @param array<string, list<string>> $picked pula → wylosowane bloki */
    public static function record(string $slide, string $date, array $picked): void
    {
        $log = self::load();
        $log[$slide][$date] = $picked;
        $cutoff = date('Y-m-d', strtotime("{$date} -" . self::KEEP . ' day'));
        foreach ($log as $name => $days) {
            $log[$name] = array_filter($days, fn($day) => $day > $cutoff, ARRAY_FILTER_USE_KEY);
        }
        @mkdir(dirname(self::FILE), 0775, true);
        file_put_contents(self::FILE, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
