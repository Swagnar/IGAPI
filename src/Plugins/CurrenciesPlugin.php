<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Koszyk walut: średnie kursy NBP (tabela A) dla najważniejszych walut z ostatniego roku (255 notowań) — kurs,
 * zmiana od poprzedniego notowania i w rok, a do wykresu cały rok wybranej waluty. Rosnący kurs = słabszy złoty.
 * Wygląd — Table i LineChart w plugins/currencies.json.
 */
class CurrenciesPlugin extends AbstractPlugin
{
    private const URL = 'https://api.nbp.pl/api/exchangerates/rates/A/%s/last/255/?format=json';

    /** Kod → [nazwa, końcówka czasownika: podrożał / podrożało / podrożała] — NBP podaje kurs za 1 jednostkę */
    private const CODES = [
        'EUR' => ['euro', 'o'],
        'USD' => ['dolar', ''],
        'CHF' => ['frank szwajcarski', ''],
        'GBP' => ['funt', ''],
        'CZK' => ['korona czeska', 'a'],
        'UAH' => ['hrywna', 'a'],
    ];

    public function __construct(private readonly JsonClient $http)
    {
        Console::boot("CurrenciesPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Koszyk walut'; }

    public function getCredit(): string { return 'NBP'; }

    public function fetchData(): array
    {
        Console::plugin("CurrenciesPlugin: pobieram kursy z NBP...");
        $rates = [];
        foreach (array_keys(self::CODES) as $code) {
            $series = [];
            foreach ($this->http->get(sprintf(self::URL, $code))['rates'] ?? [] as $r) {
                $series[$r['effectiveDate']] = (float) $r['mid'];
            }
            if (count($series) < 2) {
                throw new RuntimeException("NBP: brak kursów {$code}");
            }
            $rates[$code] = $series;
        }

        $date = array_key_last($rates['EUR']);
        Console::success(sprintf('CurrenciesPlugin: %s — EUR %.4f, USD %.4f', $date, end($rates['EUR']), end($rates['USD'])));
        return ['date' => $date, 'rates' => $rates];
    }

    /** Pola dla plugins/currencies.json: nagłówek, tabela, waluta, która najmocniej się ruszyła, i wykresy. */
    public function present(array $data): array
    {
        $rows   = [];
        $moves  = [];
        $charts = [];
        foreach (self::CODES as $code => [$name]) {
            $series = $data['rates'][$code];
            $values = array_values($series);
            $now    = end($values);
            $day    = ($now / $values[count($values) - 2] - 1) * 100;
            $year   = ($now / $values[0] - 1) * 100;
            $moves[$code] = $year;

            $rows[] = ['cells' => [$code, $name, $this->money($now), $this->change($day), $this->change($year)],
                'color' => $year > 0.5 ? '#FCA5A5' : ($year < -0.5 ? '#86EFAC' : '#E2E8F0')];
            $charts[$code] = array_map(fn($d, $v) => ['x' => strtotime($d), 'y' => $v], array_keys($series), $values);
        }

        // Największa zmiana w rok — w którą stronę poszedł złoty
        uasort($moves, fn($a, $b) => abs($b) <=> abs($a));
        $code  = array_key_first($moves);
        $move  = $moves[$code];
        [$name, $suffix] = self::CODES[$code];

        $eur = end($data['rates']['EUR']);
        $usd = end($data['rates']['USD']);
        return [
            'dateText'  => date('d.m.Y', strtotime($data['date'])),
            'headline'  => sprintf('1 € = %s zł · 1 $ = %s zł', number_format($eur, 2, ',', ' '), number_format($usd, 2, ',', ' ')),
            'moverText' => sprintf('Przez rok %s %s o %s%% — złoty %s', $name, ($move > 0 ? 'podrożał' : 'potaniał') . $suffix,
                number_format(abs($move), 1, ',', ' '), $move > 0 ? 'słabszy' : 'mocniejszy'),
            'moverColor' => $move > 0 ? '#F87171' : '#4ADE80',
            'rows'      => $rows,
            'charts'    => $charts,
        ] + $data;
    }

    /** 4.2412 → „4,2412 zł” */
    private function money(float $v): string
    {
        return number_format($v, 4, ',', ' ') . ' zł';
    }

    /** +0,12% / −3,4% */
    private function change(float $pct): string
    {
        $abs = number_format(abs($pct), abs($pct) < 10 ? 2 : 1, ',', ' ');
        return match (true) {
            $pct > 0.005  => "▲ {$abs}%",
            $pct < -0.005 => "▼ {$abs}%",
            default       => '= 0,00%',
        };
    }
}
