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
git clone https://github.com/twoj-user/igapi.git
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
├── GdImageHelper            # Zestaw helperów do rysowania na płótnie GD (tekst, paski, ramki, tło, ikony, zapis)
└── Console                   # Kolorowy output w terminalu (boot/module/plugin/info/success/warn/error)
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

Każdy plugin implementuje `getLabel()`, `fetchData()` oraz `draw(GdImage $img, array $data, Bounds $bounds)` i rysuje swoją zawartość wyłącznie w przydzielonym mu fragmencie (`Bounds`) canvasu.

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

Zaimplementuj interfejs `StoryModule`:

```php
use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

class MojModul implements StoryModule
{
    public function getLabel(): string
    {
        return 'Mój moduł';
    }

    public function run(): StoryPayload
    {
        // Wygeneruj obrazek, zapisz go na dysku i zwróć payload
        $path = '/sciezka/do/obrazka.png';
        $url  = 'https://twoja-domena.pl/obrazek.png';

        return StoryPayload::single($path, $url, $this->getLabel());
    }
}
```

Następnie zarejestruj go w `run.php`:

```php
$modules = [
    new MojModul($http),
];
```

## Tworzenie własnego pluginu do `NewsModule`

Zaimplementuj interfejs `NewsPlugin`:

```php
use Swagnar\Igapi\Contracts\NewsPlugin;
use Swagnar\Igapi\Contracts\Bounds;
use GdImage;

class MojPlugin implements NewsPlugin
{
    public function getLabel(): string
    {
        return 'Mój plugin';
    }

    public function fetchData(): array
    {
        // Pobierz i zwróć dane potrzebne do rysowania
        return [];
    }

    public function draw(GdImage $img, array $data, Bounds $bounds): void
    {
        // Rysuj wyłącznie wewnątrz przydzielonego $bounds
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
    │   └── Bounds.php
    ├── Helper/
    │   ├── Console.php
    │   └── GdImageHelper.php
    ├── Http/
    │   ├── JsonClient.php
    │   └── InstagramPublisher.php
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
        └── PopulationPlugin.php
```