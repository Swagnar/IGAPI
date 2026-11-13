<?php
namespace Swagnar\Igapi\Contracts;

use Swagnar\Igapi\Contracts\NewsPlugin;
use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Layout\Component;
use GdImage;
use RuntimeException;

/**
 * Plugin = dane w PHP + wygląd w JSON. fetchData() pobiera surowe dane (trafiają do cache), present() dolicza
 * z nich gotowe pola dla szablonu (teksty, kolory, wiersze wykresów), a layout jest w plugins/<id>.json
 * i edytuje się go w edytorze jak każdy inny blok. content() zostało tylko dla starszych pluginów.
 */
abstract class AbstractPlugin implements NewsPlugin
{
    /** Klucz w danych z fetchData(): plugin nie ma dziś nic do pokazania (np. brak przelotu ISS). */
    public const SKIP = '_skip';

    /**
     * Czy plugin bywa pomijany — nadpisz na true, jeśli fetchData() może zwrócić skip().
     * Edytor oznacza takie pluginy w drzewie; w kontenerze z 'pick' w ich miejsce losuje się inny blok.
     */
    public function canSkip(): bool
    {
        return false;
    }

    /** Wynik fetchData() na dzień bez danych: return $this->skip('Brak przelotu ISS nad Polską'); */
    protected function skip(string $reason): array
    {
        return [self::SKIP => $reason];
    }

    /** Powód pominięcia albo null, gdy plugin ma dziś dane. */
    public static function skipReason(mixed $data): ?string
    {
        return is_array($data) && isset($data[self::SKIP]) ? (string) $data[self::SKIP] : null;
    }

    /**
     * Czy dane po present() mają dziś coś do pokazania — dla pluginów, które nie mogą użyć skip(), bo są
     * doklejone do cudzego bloku (np. zorza do mapy pogody). Strona WWW oznacza plugin bez treści na czerwono.
     */
    public function hasContent(array $presented): bool
    {
        return true;
    }

    abstract public function getLabel(): string;

    /** Dostawca danych do stopki slajdu („NASA”, „Open-Meteo”) — null, gdy dane są lokalne. */
    public function getCredit(): ?string
    {
        return null;
    }

    abstract public function fetchData(): array;

    /**
     * Pola dla szablonu liczone przy rysowaniu z danych z cache — np. sformatowana cena i kolor zmiany.
     * Liczone tu, a nie w fetchData(), żeby zmiana wyglądu nie wymagała ponownego pobrania danych.
     */
    public function present(array $data): array
    {
        return $data;
    }

    /** Stary sposób: layout w PHP. Nowe pluginy rysuje plugins/<id>.json — patrz opis klasy. */
    protected function content(array $data): Component
    {
        throw new RuntimeException(static::class . ': wygląd jest w plugins/*.json — wstaw go blokiem { "type": "Plugin", "plugin": "<id>" }');
    }

    final public function draw(GdImage $img, array $data, Bounds $bounds): void
    {
        $this->content($data)->render($img, $bounds);
    }

    /** Treść pluginu jako komponent — do osadzenia w layoucie modułu (np. w Stack), a nie w stałym slocie. */
    final public function component(array $data): Component
    {
        return $this->content($data);
    }
}