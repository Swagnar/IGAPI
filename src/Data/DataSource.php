<?php
namespace Swagnar\Igapi\Data;

/**
 * Źródło danych dla slajdów opisanych w slides/*.json. Zwraca gotowe do wstawienia wartości
 * (teksty, liczby, URL-e) — szablon odwołuje się do nich przez {{id.pole}}.
 */
interface DataSource
{
    /** Klucz w szablonie, np. 'asteroids' → {{asteroids.header}} */
    public function id(): string;

    public function label(): string;

    /**
     * Dostawca danych do stopki slajdu ({{credits}}): „NASA”, „Steam / steamcharts.com”; null — dane lokalne.
     * Trafia do stopki tylko wtedy, gdy dane źródła zostały dziś faktycznie narysowane.
     */
    public function credit(): ?string;

    /** Opis integracji (endpoint, parametry — bez kluczy API) do podglądu w edytorze. */
    public function describe(): array;

    /** Rzuca wyjątek, gdy API zawiedzie — DataCache sięga wtedy po ostatnie zapisane dane. */
    public function fetch(): array;

    /** Jak długo (w sekundach) zapisane dane nadają się na zastępstwo przy błędzie API; null = bez limitu. */
    public function maxFallbackAge(): ?int;
}
