<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\NewsPlugin;
use Swagnar\Igapi\Contracts\Bounds;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Helper\GdImageHelper;

use GdImage;

class PopulationPlugin implements NewsPlugin
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

    public function fetchData(): array { return [$this->getWorldPopulationNumber()]; }

    public function draw(GdImage $img, array $data, Bounds $bounds): void {
        $cy = $bounds->y + 55;
        $count = $this->getWorldPopulationNumber();
        $formattedCount = number_format($count, 0, ',', ' ');

        GdImageHelper::drawText($img, 'Ilu nas jest?', 60, $cy, 28, '#FFFFFF', FONT_BOLD);
        GdImageHelper::centerText($img, $formattedCount, $cy + 70, 54, '#FFFFFF', FONT_BOLD);

        $this->drawCountryPopulationChart($img, $this->getTopCountriesByPopulation(10), $cy + 60);
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

    private function drawCountryPopulationChart(GdImage $img, array $countries, int $y): int
    {
        $rowH      = 56;
        $flagW     = 32;
        $flagH     = 24;
        $barStartX = 50 + $flagW + 200;   // po fladze + nazwie
        $barMaxW   = WIDTH - $barStartX - 80;
        $maxPop    = $countries[0]['population'];

        $y += 40;

        foreach ($countries as $i => $country) {
            $cy      = $y + (int)($i * $rowH);
            $barY    = $cy;
            $flagY   = $cy + 4;

            // ── Flaga PNG z flagcdn.com ───────────────────────────────────────────
            $flagUrl = "https://flagcdn.com/{$flagW}x{$flagH}/{$country['code']}.png";
            try {
                $flagData = @file_get_contents($flagUrl);
                if ($flagData !== false) {
                    $flagImg = @imagecreatefromstring($flagData);
                    if ($flagImg !== false) {
                        imagecopy($img, $flagImg, 60, $flagY, 0, 0, $flagW, $flagH);
                        imagedestroy($flagImg);
                    }
                }
            } catch (\Throwable) {
                // flaga niedostępna — kontynuuj bez niej
            }

            // ── Nazwa kraju ───────────────────────────────────────────────────────
            GdImageHelper::drawText($img, $country['name'], 60 + $flagW + 14, $cy + 26, 22, '#CCDDFF', FONT);

            // ── Pasek ─────────────────────────────────────────────────────────────
            $barW   = (int)(($country['population'] / $maxPop * $barMaxW) - 60);
            $hue    = (int)(200 + $i * 8);   // lekko różnicowany odcień niebieskiego
            $barCol = imagecolorallocate($img, 40, 100 + $i * 8, 220 - $i * 6);
            imagefilledrectangle($img, $barStartX, $barY, $barStartX + $barW, $barY + 28, $barCol);

            // ── Liczba po prawej stronie paska ────────────────────────────────────
            $popStr = number_format((int)($country['population'] / 1_000_000), 0, ',', ' ') . ' mln';
            GdImageHelper::drawText($img, $popStr, $barStartX + $barW + 8, $cy + 22, 15, '#8899BB', FONT);
        }

        return $y + count($countries) * $rowH + 20;
    }
}