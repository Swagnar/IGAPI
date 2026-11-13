<?php
namespace Swagnar\Igapi\Template;

use RuntimeException;

/**
 * Pluginy opisane w JSON — plugins/<id>.json: grupa bloków z własnymi źródłami, wielokrotnego użytku.
 *
 *   { "label": "Asteroidy", "sources": ["asteroids"], "body": { "type": "Stack", "children": [ … ] } }
 *
 * W slajdzie: { "type": "Plugin", "plugin": "asteroids" } (pluginy w PHP dalej przez "source").
 * "enabled": false wyłącza plugin wszędzie: nie rysuje się, nie losuje w 'pick' i nie pobiera danych.
 * Edytor otwiera je jak slajdy, a podgląd pokazuje je w połowie slajdu — tak jak trafią na ŚWIAT/KOSMOS.
 */
final class JsonPlugins
{
    /** Plugin w pluginie jest dozwolony, ale głębiej to już najpewniej pętla */
    public const MAX_DEPTH = 4;

    public static function dir(): string
    {
        return ROOT_DIR . '/plugins';
    }

    public static function file(string $id): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $id)) {
            throw new RuntimeException("Nieprawidłowa nazwa pluginu: {$id}");
        }
        return self::dir() . "/{$id}.json";
    }

    public static function load(string $id): array
    {
        $file = self::file($id);
        if (!is_file($file)) {
            throw new RuntimeException("Brak pluginu plugins/{$id}.json");
        }
        $definition = json_decode((string) file_get_contents($file), true);
        if (!is_array($definition) || !isset($definition['body']['type'])) {
            throw new RuntimeException("plugins/{$id}.json: nieprawidłowy JSON albo brak body (" . json_last_error_msg() . ')');
        }
        return $definition;
    }

    /** Plugin bez pola enabled jest włączony. */
    public static function enabled(array $definition): bool
    {
        return ($definition['enabled'] ?? true) !== false;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn($f) => basename($f, '.json'), glob(self::dir() . '/*.json') ?: []);
    }

    /**
     * Wszystkie pluginy JSON użyte w drzewie (także w pluginach w pluginach) → id => definicja.
     * Błędny plugin trafia jako ['error' => …] — wybucha dopiero przy rysowaniu, w swoim bloku.
     */
    public static function collect(array $node, array $found = [], int $depth = 0): array
    {
        if (($node['type'] ?? null) === ComponentFactory::PLUGIN && isset($node['plugin']) && !isset($found[$node['plugin']])) {
            $id = (string) $node['plugin'];
            try {
                $found[$id] = self::load($id);
                if ($depth < self::MAX_DEPTH) {
                    $found = self::collect($found[$id]['body'], $found, $depth + 1);
                }
            } catch (RuntimeException $e) {
                $found[$id] = ['error' => $e->getMessage()];
            }
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $found = self::collect($child, $found, $depth);
            }
        }
        return $found;
    }

    /** Źródła potrzebne slajdowi: jego własne + użytych pluginów JSON (wyłączone nie pobierają danych). */
    public static function sources(array $definition, array $plugins): array
    {
        $ids = $definition['sources'] ?? [];
        foreach ($plugins as $plugin) {
            if (!self::enabled($plugin)) {
                continue;
            }
            array_push($ids, ...($plugin['sources'] ?? []));
        }
        return array_values(array_unique($ids));
    }
}
