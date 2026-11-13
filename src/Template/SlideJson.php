<?php
namespace Swagnar\Igapi\Template;

/**
 * Zapis szablonu slajdu w czytelnym formacie: węzły bez zagnieżdżeń w jednej linii,
 * kontenery rozpisane — tak, żeby diff w gicie pokazywał zmienione bloki, a nie przeformatowany plik.
 */
final class SlideJson
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** Stała kolejność kluczy — pole dodane w edytorze ląduje w swoim miejscu, a nie na końcu pliku */
    private const SLIDE_ORDER = ['label', 'enabled', 'title', 'subtitle', 'footer', 'sources'];
    private const NODE_ORDER  = ['type', 'name', 'when', 'order', 'pick'];

    public static function encode(array $definition): string
    {
        return self::value(self::ordered($definition, self::SLIDE_ORDER, 'body'), 0) . "\n";
    }

    private static function ordered(array $value, array $first, string $last): array
    {
        $head = array_intersect_key(array_flip($first), $value);
        $tail = array_key_exists($last, $value) ? [$last => $value[$last]] : [];

        return array_replace($head, $value, $tail);
    }

    private static function value(mixed $value, int $depth): string
    {
        if (!is_array($value) || $value === []) {
            return json_encode($value, self::FLAGS);
        }
        if (isset($value['type'])) {
            $value = self::ordered($value, self::NODE_ORDER, 'children');
        }
        if (self::isFlat($value)) {
            return self::inline($value);
        }

        $pad   = str_repeat('    ', $depth + 1);
        $list  = array_is_list($value);
        $lines = [];
        foreach ($value as $key => $item) {
            $prefix  = $list ? '' : json_encode((string) $key, self::FLAGS) . ': ';
            $lines[] = $pad . $prefix . self::value($item, $depth + 1);
        }

        [$open, $close] = $list ? ['[', ']'] : ['{', '}'];
        return $open . "\n" . implode(",\n", $lines) . "\n" . str_repeat('    ', $depth) . $close;
    }

    /** { "type": "Text", "size": 28 } / ["a", "b"] — ze spacjami, jak w ręcznie pisanych szablonach */
    private static function inline(array $value): string
    {
        if (array_is_list($value)) {
            return '[' . implode(', ', array_map(fn($v) => json_encode($v, self::FLAGS), $value)) . ']';
        }

        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = json_encode((string) $key, self::FLAGS) . ': ' . json_encode($item, self::FLAGS);
        }
        return '{ ' . implode(', ', $pairs) . ' }';
    }

    /** Płaski = bez zagnieżdżonych tablic/obiektów (lista skalarów albo węzeł-liść). */
    private static function isFlat(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) && $item !== []) {
                return false;
            }
        }
        return true;
    }
}
