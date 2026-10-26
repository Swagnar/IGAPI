# IG API — Instagram Story Publisher

```
  ██╗ ██████╗      █████╗ ██████╗ ██╗
  ██║██╔════╝     ██╔══██╗██╔══██╗██║
  ██║██║  ███╗    ███████║██████╔╝██║
  ██║██║   ██║    ██╔══██║██╔═══╝ ██║
  ██║╚██████╔╝    ██║  ██║██║     ██║
  ╚═╝ ╚═════╝     ╚═╝  ╚═╝╚═╝     ╚═╝
```

Narzędzie CLI do automatycznego generowania i publikowania Story na Instagramie. Modułowa architektura pozwala na łatwe dodawanie nowych typów treści, a system pluginów wewnątrz `NewsModule` pozwala łączyć wiele niezależnych źródeł danych na jednym slajdzie. Każdy moduł generuje obrazek i publikuje go przez Meta Graph API.

---

## Funkcje

- Automatyczne generowanie grafik Story (1080×1920 px) z użyciem GD
- Publikacja przez Meta Graph API (z oczekiwaniem na gotowość kontenera)
- Modułowa struktura (`StoryModule`) + system pluginów (`NewsPlugin`) do łączenia wielu źródeł danych na jednym slajdzie
- Deterministyczny, dzienny losowy dobór treści
- Tryb `DEBUG` do lokalnego testowania bez publikacji
- Tłumaczenie tekstów (np. opisów zdjęć NASA) na polski przez Google Translate

---

## Wymagania

- PHP 8.1+
- Rozszerzenia PHP: `gd`, `curl`
- Composer
- Fonty `Inter-Regular.ttf` i `Inter-Bold.ttf` w katalogu głównym projektu
- Konto Instagram Business + aplikacja Meta z tokenem dostępu
- Klucz API NASA (dla `AsteroidsPlugin`, `DisasterPlugin`, `MarsModule`)

---

## Instalacja

```bash
git clone https://github.com/Swagnar/IGAPI.git
cd igapi
composer install
cp .env.example .env
```

Uzupełnij plik `.env`:

```env
PAGE_ACCESS_TOKEN=twoj_token_dostepu
IG_ID=twoje_ig_user_id
NASA_API_KEY=twoj_klucz_nasa
```

---

## Uruchomienie

```bash
php run.php
```

W trybie debug (lokalne pliki, bez publikacji) ustaw w `run.php`:

```php
define('DEBUG', true);
```

W tym trybie obrazki zapisywane są lokalnie do katalogu `ig-stories/`, a publikacja do Instagrama jest pomijana.

---

## Architektura

```
run.php                  # Punkt wejścia — konfiguracja, rejestracja modułów
├── StoryRunner           # Orchestrator — iteruje po modułach i publikuje wynik
├── StoryModule           # Interfejs — każdy moduł "top-level" musi go implementować
├── StoryPayload          # DTO — zwracany przez moduły (ścieżka + URL slajdów)
├── NewsPlugin             # Interfejs — pluginy rysowane wewnątrz NewsModule
├── Bounds                 # DTO — prostokątny obszar (x, y, width, height) do rysowania
├── InstagramPublisher     # Wysyła obrazek do Meta Graph API i czeka na status kontenera
├── JsonClient              # HTTP client (GET/POST/cURL)
├── GdImageHelper            # Niskopoziomowe helpery GD (tekst, tło, ikony, pobieranie obrazków, tłumaczenie, zapis)
├── Layout\*                  # Komponenty do składania slajdów (patrz niżej)
└── Console                   # Kolorowy output w terminalu (boot/module/plugin/info/success/warn/error)
```

### Layout

Slajdy składa się z komponentów (`Layout\Component`), zamiast rysować tekst w ręcznie liczonych współrzędnych. Każdy komponent dostaje prostokąt (`Bounds`), rysuje się w nim i zwraca zajętą wysokość.

| Komponent | Do czego |
|---|---|
| `Slide` | Szkielet Story: tło, tytuł (+ podtytuł), obszar treści, stopka, zapis do pliku |
| `Stack` | Układa dzieci jedno pod drugim (z opcjonalnym `gap`) |
| `VerticalSplit` | Dzieli obszar na równe sloty (np. pluginy w `NewsModule`) |
| `Text`, `WrappedText` | Tekst w jednej linii (left/center/right) i tekst zawijany |
| `Stat` | Wycentrowana etykieta + duża liczba |
| `Photo` | Zdjęcie z sieci, skalowane do dostępnego miejsca, z podpisem i opisem |
| `LineChart`, `BarChart` | Wykres liniowy (oś X proporcjonalna do czasu) i poziomy słupkowy |
| `Separator`, `Spacer` | Linia pozioma / pusty odstęp |
| `Custom` | Furtka na rysowanie niestandardowe (np. mapa pogody) |

```php
$slide = new Slide('MARS', footer: Slide::source('Źródło: NASA'));
[$path, $url] = $slide->render(
    (new Stack())
        ->add(new Text('Pogoda w kraterze Gale', size: 28, color: '#FFFFFF', font: FONT_BOLD, align: 'center'))
        ->add(new Separator())
        ->add(new Photo($url, caption: 'Podpis', maxHeight: 400))
)->save('mars');
```

### Przepływ działania

```
run.php
  └─► StoryRunner::run()
        └─► foreach moduł ze StoryModule[]:
              ├─► StoryModule::run() → StoryPayload
              └─► foreach slajd → InstagramPublisher::publish($url)   (pomijane w trybie DEBUG)
```

`NewsModule` dodatkowo, wewnątrz swojego `run()`, losuje (deterministycznie, seedem z bieżącej daty) zestaw zarejestrowanych `NewsPlugin`, dzieli dostępną wysokość canvasu na równe sloty (`Bounds`) i wywołuje na każdym z nich `fetchData()` oraz `draw()`.

---

## Moduły (`StoryModule`)

### `NewsModule`
Łączy kilka niezależnych pluginów danych na jednym slajdzie. Co dzień losuje (seed = data) `N` pluginów spośród zarejestrowanych, dzieli canvas na równe sloty i rysuje w nich ramki z podpisem oraz zawartość każdego pluginu. Błąd pojedynczego pluginu nie przerywa całego modułu — jest logowany i pomijany.

**Dostępne:** `BitcoinPlugin`, `PopulationPlugin`, `AsteroidsPlugin`, `DisasterPlugin`

### `WeatherModule`
Pobiera dane pogodowe z [Open-Meteo](https://open-meteo.com/) dla największych miast Polski (prognoza na godz. 12:00) i generuje mapę cieplną kraju z interpolacją IDW na podstawie granic Polski (`geo/poland-border.php`). Dolicza medianę temperatury i losowy komentarz do wyniku.

**Źródło:** `api.open-meteo.com`

### `PalestineModule`
Pobiera codzienne dane o ofiarach z [Tech for Palestine API](https://data.techforpalestine.org/) i generuje Story z liczbą zabitych danego dnia, sumą łączną, zmianą względem poprzedniego raportu oraz podziałem na dzieci/kobiety/dziennikarzy. Rysuje tło złożone z kropek proporcjonalne do liczby ofiar. Obsługuje brak raportu dla wczorajszego dnia — wyświetla wtedy ostatni dostępny raport.

**Źródło:** `data.techforpalestine.org`

### `MarsModule`
Generuje slajd łączący: aktualną pogodę w kraterze Gale (łazik Curiosity), obliczony status misji (dni aktywności, przybliżony sol), losowe zdjęcie z Curiosity (z tłumaczeniem opisu na polski) oraz jeden losowy, dzienny fakt obalający płaską Ziemię.

**Źródła:** `mars.nasa.gov` (pogoda), `images-api.nasa.gov` (zdjęcia)

### `RemembranceModule` *(w przygotowaniu)*
Moduł zarezerwowany na przyszłe funkcje upamiętniające — obecnie tylko loguje, że nie ma jeszcze funkcjonalności.

---

## Pluginy (`NewsPlugin`, używane przez `NewsModule`)

Każdy plugin rozszerza `AbstractPlugin`: implementuje `getLabel()`, `fetchData()` oraz `content(array $data): Component` i rysuje się wyłącznie w przydzielonym mu slocie (`Bounds`) canvasu.

### `AsteroidsPlugin`
Pobiera z NASA NeoWs listę asteroid, które przeleciały wczoraj blisko Ziemi, znajduje najbliższe przejście, pokazuje dystans oraz pasek proporcji względem odległości do Księżyca (lub mnożnik, gdy dystans jest poza skalą). Dokleja stałą notkę o zbliżeniu (99942) Apophis w 2029 r.

**Źródło:** `api.nasa.gov/neo/rest/v1/feed`

### `DisasterPlugin`
Pobiera aktywne zdarzenia (pożary, trzęsienia ziemi, powodzie, burze, wulkany, osunięcia ziemi, susze, śnieg, ekstremalne temperatury) z NASA EONET, grupuje i sortuje je wg kategorii, a następnie próbuje dobrać pasujące zdjęcie z NASA Image and Video Library (kilka kolejnych, coraz mniej precyzyjnych zapytań). Podpisy zdjęć tłumaczone są na polski.

**Źródła:** `eonet.gsfc.nasa.gov`, `images-api.nasa.gov`

### `BitcoinPlugin`
Pobiera aktualną cenę BTC z CoinGecko, dopisuje ją do lokalnego pliku historii (`btc_data.txt`, z ochroną przed duplikatami i nierealnymi skokami ceny) i rysuje wykres liniowy ostatnich odczytów.

**Źródło:** `api.coingecko.com`

### `PopulationPlugin`
Szacuje bieżącą populację świata na podstawie ekstrapolacji liniowej oraz rysuje wykres słupkowy 10 najludniejszych krajów (z flagami) na podstawie danych World Bank.

**Źródła:** World Bank API, `flagcdn.com`

---

## Tworzenie własnego modułu

Zaimplementuj interfejs `StoryModule` i złóż slajd z komponentów `Layout`:

```php
use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Stat;

class MojModul implements StoryModule
{
    public function getLabel(): string
    {
        return 'Mój moduł';
    }

    public function run(): StoryPayload
    {
        $body = (new Stack(gap: 40))
            ->add(new Stat('Odpowiedź', '42'));

        [$path, $url] = (new Slide('MÓJ MODUŁ', footer: Slide::source('Źródło: ja')))
            ->render($body)
            ->save('moj-modul');

        return StoryPayload::single($path, $url, $this->getLabel());
    }
}
```

Moduł, który danego dnia nie ma nic do opublikowania, może zwrócić `new StoryPayload([], $this->getLabel())`.

Następnie zarejestruj go w `run.php`:

```php
$modules = [
    new MojModul($http),
];
```

## Tworzenie własnego pluginu do `NewsModule`

Rozszerz `AbstractPlugin` — pobierz dane w `fetchData()`, a w `content()` zwróć komponent, który zostanie narysowany w przydzielonym slocie:

```php
use Swagnar\Igapi\Contracts\AbstractPlugin;
use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;

class MojPlugin extends AbstractPlugin
{
    public function getLabel(): string
    {
        return 'Mój plugin';
    }

    public function fetchData(): array
    {
        // Pobierz i zwróć dane potrzebne do rysowania (tu też tłumaczenia itp.)
        return ['value' => 123];
    }

    protected function content(array $data): Component
    {
        return (new Stack())
            ->add(new Text('Mój plugin', size: 28, color: '#FFFFFF', font: FONT_BOLD))
            ->add(new Text((string) $data['value'], size: 54, color: '#FFFFFF', font: FONT_BOLD, align: 'center', height: 70));
    }
}
```

Następnie zarejestruj go w `NewsModule::$availablePlugins`.

---

## Struktura katalogów

```
igapi/
├── run.php
├── .env
├── Inter-Regular.ttf
├── Inter-Bold.ttf
├── btc_data.txt              # Lokalna historia cen BTC (BitcoinPlugin)
├── geo/
│   └── poland-border.php     # Współrzędne granic Polski (WeatherModule)
├── ig-stories/                # Wygenerowane obrazki (tryb DEBUG i produkcja)
├── vendor/
└── src/
    ├── Contracts/
    │   ├── StoryModule.php
    │   ├── StoryPayload.php
    │   ├── StoryRunner.php
    │   ├── NewsPlugin.php
    │   ├── AbstractPlugin.php
    │   └── Bounds.php
    ├── Helper/
    │   ├── Console.php
    │   └── GdImageHelper.php
    ├── Http/
    │   ├── JsonClient.php
    │   └── InstagramPublisher.php
    ├── Layout/
    │   ├── Component.php
    │   ├── Slide.php
    │   ├── Stack.php
    │   ├── VerticalSplit.php
    │   ├── Text.php
    │   ├── WrappedText.php
    │   ├── Stat.php
    │   ├── Photo.php
    │   ├── LineChart.php
    │   ├── BarChart.php
    │   ├── Separator.php
    │   ├── Spacer.php
    │   └── Custom.php
    ├── Modules/
    │   ├── WeatherModule.php
    │   ├── PalestineModule.php
    │   ├── MarsModule.php
    │   ├── NewsModule.php
    │   └── RemembranceModule.php
    └── Plugins/
        ├── AsteroidsPlugin.php
        ├── DisasterPlugin.php
        ├── BitcoinPlugin.php
        ├── PopulationPlugin.php
        └── MoonProximityBar.php
```
