<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Layout\BarChart;
use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Spacer;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;

class PopulationPlugin extends AbstractPlugin
{
    public function __construct(
        private readonly JsonClient $http,
    )
    {
        Console::boot("PopulacjaPlugin zainicjalizowany");
    }

    public function getLabel(): string
    {
        return 'Populacja';
    }

    public function fetchData(): array
    {
        return [
            'population' => $this->getWorldPopulationNumber(),
            'countries'  => $this->getTopCountriesByPopulation(10),
        ];
    }

    protected function content(array $data): Component
    {
        $rows = array_map(fn($country) => [
            'label'      => $country['name'],
            'value'      => $country['population'],
            'valueLabel' => number_format((int)($country['population'] / 1_000_000), 0, ',', ' ') . ' mln',
            'icon'       => "https://flagcdn.com/32x24/{$country['code']}.png",
        ], $data['countries']);

        return (new Stack())
            ->add(new Text('Ilu nas jest?', size: 28, color: '#FFFFFF', font: FONT_BOLD, indent: 20, height: 61))
            ->add(new Text(number_format($data['population'], 0, ',', ' '), size: 54, color: '#FFFFFF', font: FONT_BOLD, align: 'center', height: 70))
            ->add(new Spacer(30))
            ->add(new BarChart($rows, indent: 20));
    }

    private function getWorldPopulationNumber(): int {
        $base     = 8_119_301_000;
        $baseTime = mktime(0, 0, 0, 1, 1, 2025);
        $elapsed  = time() - $baseTime;
        $current  = $base + (int)($elapsed * 2.3);

        return $current;
    }

    private function getTopCountriesByPopulation(int $limit = 10): array
    {
        Console::info("Pobieram dane populacji krajów z World Bank API...");

        // World Bank API: bezpłatne, bez klucza, zwraca populację per kraj (ostatni dostępny rok)
        $url      = 'https://api.worldbank.org/v2/country/all/indicator/SP.POP.TOTL?format=json&per_page=300&mrv=1';
        $response = $this->http->getCurl($url);
        $rows     = $response['data'][1] ?? null;

        if (!is_array($rows)) {
            throw new \RuntimeException("World Bank API zwróciło nieprawidłowe dane");
        }

        // Mapowanie ISO2 → polska nazwa (top kraje ręcznie, reszta po angielsku)
        $polishNames = [
            'CN' => 'Chiny',       'IN' => 'Indie',          'US' => 'USA',
            'ID' => 'Indonezja',   'PK' => 'Pakistan',       'BR' => 'Brazylia',
            'NG' => 'Nigeria',     'BD' => 'Bangladesz',     'RU' => 'Rosja',
            'ET' => 'Etiopia',     'MX' => 'Meksyk',         'EG' => 'Egipt',
            'CD' => 'Kongo',       'PH' => 'Filipiny',       'DE' => 'Niemcy',
            'FR' => 'Francja',     'GB' => 'Wielka Brytania','TZ' => 'Tanzania',
            'IR' => 'Iran',        'TH' => 'Tajlandia',
        ];

        $countries = [];
        foreach ($rows as $row) {
            $pop   = $row['value'] ?? null;
            $iso2  = $row['country']['id'] ?? '';   // 'id' w World Bank to ISO2
            $name  = $row['country']['value'] ?? '';

            // Pomijamy agregaty regionalne World Bank.
            // Blacklista kodów które wyglądają jak ISO2 ale są agregatami (wg surowego API):
            // EU, OE, X* (oprócz XK=Kosovo), Z* (oprócz ZA/ZM/ZW = kraje)
            $aggregateSet = ['EU','OE','XC','XD','XE','XF','XG','XH','XI','XJ',
                             'XL','XM','XN','XO','XP','XQ','XT','XU','XY',
                             'ZF','ZG','ZH','ZI','ZJ','ZQ','ZT'];
            if (
                !preg_match('/^[A-Z]{2}$/', $iso2) ||
                in_array($iso2, $aggregateSet, true) ||
                $pop === null ||
                $pop <= 0
            ) {
                continue;
            }

            $countries[] = [
                'name'       => $polishNames[$iso2] ?? $name,
                'code'       => strtolower($iso2),
                'population' => (int)$pop,
            ];
        }

        usort($countries, fn($a, $b) => $b['population'] <=> $a['population']);
        return array_slice($countries, 0, $limit);
    }
}
