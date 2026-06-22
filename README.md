# IG API — Instagram Story Publisher

```
  ██╗ ██████╗      █████╗ ██████╗ ██╗
  ██║██╔════╝     ██╔══██╗██╔══██╗██║
  ██║██║  ███╗    ███████║██████╔╝██║
  ██║██║   ██║    ██╔══██║██╔═══╝ ██║
  ██║╚██████╔╝    ██║  ██║██║     ██║
  ╚═╝ ╚═════╝     ╚═╝  ╚═╝╚═╝     ╚═╝
```

Narzędzie CLI do automatycznego generowania i publikowania Story na Instagramie. Modułowa architektura pozwala na łatwe dodawanie nowych typów treści. Każdy moduł generuje obrazek i publikuje go przez Meta Graph API.

---

## Funkcje

- Automatyczne generowanie grafik Story (1080×1920 px) z użyciem GD
- Publikacja przez Meta Graph API
- Modułowa struktura
- Tryb `DEBUG` do lokalnego testowania bez publikacji

---

## Wymagania

- PHP 8.1+
- Rozszerzenia PHP: `gd`, `curl`
- Composer
- Font `Inter-Regular.ttf` i `Inter-Bold.ttf` w katalogu głównym projektu
- Konto Instagram Business + aplikacja Meta z tokenem dostępu

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

---

## Architektura

```
run.php                  # Punkt wejścia
├── StoryRunner          # Orchestrator — iteruje po modułach i publikuje
├── StoryModule          # Interfejs — każdy moduł musi go implementować
├── StoryPayload         # DTO — zwracany przez moduły (ścieżka + URL slajdów)
├── InstagramPublisher   # Wysyła obrazek do Meta Graph API
├── JsonClient           # HTTP client (GET/POST/cURL + tłumaczenie)
└── Console              # Kolorowy output w terminalu
```

### Przepływ działania

```
run.php
  └─► StoryRunner::run()
        └─► foreach moduł:
              ├─► StoryModule::run() → StoryPayload
              └─► InstagramPublisher::publish($url)
```

---

## Moduły

### `WeatherModule`
Pobiera dane pogodowe z [Open-Meteo](https://open-meteo.com/) dla 10 największych miast Polski i generuje mapę cieplną z interpolacją IDW (Inverse Distance Weighting). Wynikowy obrazek trafia do katalogu `weather-maps/`.

**Dane:** temperatura, kod pogodowy, prędkość wiatru  
**Źródło:** `api.open-meteo.com`

### `PalestineModule`
Pobiera codzienne dane o ofiarach z [Tech for Palestine API](https://data.techforpalestine.org/) i generuje Story z liczbą zabitych, w tym dzieci, kobiet i dziennikarzy. Obsługuje brak raportu dla bieżącego dnia — wyświetla wtedy ostatni dostępny raport.

**Źródło:** `data.techforpalestine.org`

### `NewsModule` *(eksperymentalny)*
Agreguje różne dane globalne na jednej karcie: kurs BTC, liczba ludzi w kosmosie, ostatnie trzęsienie ziemi ≥5M, bieżąca populacja świata.

**Źródła:** CoinGecko, Open Notify, USGS, ONZ

### `RemembranceModule` *(w przygotowaniu)*
Moduł zarezerwowany na przyszłe funkcje upamiętniające.

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

---

## Struktura katalogów

```
igapi/
├── run.php
├── .env
├── Inter-Regular.ttf
├── Inter-Bold.ttf
├── ig-stories/           # Obrazki PalestineModule / NewsModule
├── weather-maps/         # Mapy pogody WeatherModule
├── vendor/
└── src/
    ├── Contracts/
    │   ├── StoryModule.php
    │   ├── StoryPayload.php
    │   └── StoryRunner.php
    ├── Helper/
    │   └── Console.php
    ├── Http/
    │   ├── JsonClient.php
    │   └── InstagramPublisher.php
    └── Modules/
        ├── WeatherModule.php
        ├── PalestineModule.php
        ├── NewsModule.php
        └── RemembranceModule.php
```