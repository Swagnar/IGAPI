# IG API — Instagram Story Publisher

```
  ██╗ ██████╗      █████╗ ██████╗ ██╗
  ██║██╔════╝     ██╔══██╗██╔══██╗██║
  ██║██║  ███╗    ███████║██████╔╝██║
  ██║██║   ██║    ██╔══██║██╔═══╝ ██║
  ██║╚██████╔╝    ██║  ██║██║     ██║
  ╚═╝ ╚═════╝     ╚═╝  ╚═╝╚═╝     ╚═╝
```

Narzędzie CLI, które codziennie generuje zestaw Story (1080×1920 px) z danych z publicznych API i publikuje je na Instagramie przez Meta Graph API. Każdy slajd to osobny moduł (`StoryModule`), a slajd **ŚWIAT** łączy kilka niezależnych pluginów (`NewsPlugin`) na jednej planszy.

| Slajd | Moduł | Co pokazuje |
|---|---|---|
| **GAZA** | `PalestineModule` | Dzienny raport ofiar z Tech for Palestine |
| **ŚWIAT** | `NewsModule` | Historia ceny Bitcoina + populacja świata |
| **KOSMOS** | `KosmosModule` | Wczorajsze asteroidy, fakt dla płaskoziemców, zdjęcie z Curiosity |
| **POLSKA** | `WeatherModule` | Mapa cieplna Polski z temperaturą i wilgotnością w 9 miastach |

---

## Funkcje

- Generowanie grafik GD z komponentów layoutu (`Stack`, `VerticalSplit`, `Photo`, wykresy…) zamiast ręcznie liczonych współrzędnych
- Publikacja przez Meta Graph API (z oczekiwaniem na gotowość kontenera)
- Sprawdzanie tokenu Meta przed każdym uruchomieniem — z datą wygaśnięcia i ostrzeżeniem z wyprzedzeniem
- Przy nieważnym tokenie slajdy i historia (np. ceny BTC) nadal się generują — pomijana jest tylko publikacja
- Deterministyczny, dzienny dobór treści (seed = data) — kilka uruchomień tego samego dnia daje to samo
- Tryb `DEBUG` do lokalnego testowania bez publikacji (domyślny, gdy `.env` nie mówi inaczej)
- Tłumaczenie opisów zdjęć NASA na polski
- `deploy.sh` — jedno polecenie do wdrożenia na hosting

---

## Wymagania

- PHP 8.1+ z rozszerzeniami `gd`, `curl`, `mbstring`
- Composer
- Konto Instagram Business podpięte do strony na Facebooku + aplikacja Meta
- Klucz API NASA ([api.nasa.gov](https://api.nasa.gov/)) — dla asteroid w `KosmosModule`

---

## Instalacja

```bash
git clone https://github.com/Swagnar/IGAPI.git
cd IGAPI
composer install
cp .env.example .env
```

### Konfiguracja `.env`

| Klucz | Wymagany | Opis |
|---|---|---|
| `IG_ID` | tak | ID konta Instagram Business |
| `PAGE_ACCESS_TOKEN` | tak | Token strony — wygeneruj przez `token.php` (patrz niżej) |
| `NASA_API_KEY` | tak | Klucz NASA API |
| `APP_ID`, `APP_SECRET` | zalecane | Dane aplikacji Meta — potrzebne do `token.php` i do sprawdzania daty wygaśnięcia tokenu |
| `DEBUG` | nie | `false` na produkcji. **Brak wpisu = tryb debug** (bez publikacji), żeby lokalny run niczego nie wrzucił |
| `PUBLIC_DIR`, `PUBLIC_URL` | nie | Gdzie zapisywać obrazki na produkcji i pod jakim URL-em są dostępne. Domyślnie `../swagnar.dev/ig-stories` i `https://swagnar.dev/ig-stories` |

---

## Uruchomienie

```bash
php run.php
```

W trybie debug obrazki trafiają do lokalnego `ig-stories/`, a publikacja jest pomijana. Na produkcji (`DEBUG=false`) obrazki zapisywane są w `PUBLIC_DIR` — musi to być katalog w document root domeny z `PUBLIC_URL`, bo Instagram pobiera obrazki po URL-u.

---

## Token Meta

Meta nie pozwala odnowić **wygasłego** tokenu przez API — trzeba wygenerować nowy. Najlepiej od razu taki, który nie wygasa: token strony uzyskany z długoterminowego tokenu użytkownika.

1. W [Graph API Explorer](https://developers.facebook.com/tools/explorer) wybierz aplikację → **Get User Access Token** z uprawnieniami `instagram_basic`, `instagram_content_publish`, `pages_show_list`, `pages_read_engagement`, `business_management`.
2. Uruchom:
   ```bash
   php token.php 'KRÓTKI_TOKEN_Z_EXPLORERA'
   ```
   Skrypt wymienia token na długoterminowy, pobiera token strony podpiętej do `IG_ID`, weryfikuje go przez `debug_token` i zapisuje w `.env` jako `PAGE_ACCESS_TOKEN`. Powinien pokazać **„Wygasa: nigdy”**.

Przed każdym uruchomieniem `InstagramPublisher::checkTokenStatus()` sprawdza token (przez `debug_token`, gdy są `APP_ID`/`APP_SECRET`). Na 14 dni przed wygaśnięciem wypisuje ostrzeżenie, a przy nieważnym tokenie zapisuje obrazek z alertem i pomija publikację.

---

## Deploy i produkcja

Produkcja działa na hostingu cPanel (`vh13519@v102.vh.net.pl`) w katalogu `~/IGAPI`.

```bash
./deploy.sh          # wysyła kod (src/, geo/, run.php, …) i robi composer install --no-dev
./deploy.sh --run    # to samo + próbne uruchomienie na serwerze w trybie debug
```

`deploy.sh` nie rusza danych produkcyjnych: `.env`, `btc_data.txt` i wygenerowanych obrazków. Ścieżki można nadpisać zmiennymi `SSH_HOST`, `SSH_KEY`, `REMOTE_DIR`, `PUBLIC_DIR`, `PHP_BIN`.

**Harmonogram** — zadanie CRON w cPanelu, codziennie o 06:00 (czas serwera = CET):

```
0 6 * * * /usr/local/bin/php /home/vh13519/IGAPI/run.php >> /home/vh13519/IGAPI/cron.log 2>&1
```

Log z każdego uruchomienia trafia do `~/IGAPI/cron.log`.

---

## Architektura

```
run.php                   # Punkt wejścia — konfiguracja z .env, rejestracja modułów
├── StoryRunner            # Sprawdza token, uruchamia moduły, publikuje slajdy
├── StoryModule            # Interfejs modułu (jeden lub więcej slajdów)
├── StoryPayload           # DTO — ścieżki i URL-e slajdów zwrócone przez moduł
├── NewsPlugin             # Interfejs pluginu (AbstractPlugin — wspólna baza)
├── Bounds                 # Prostokąt (x, y, width, height), w którym rysuje się komponent
├── InstagramPublisher     # Graph API: kontener → oczekiwanie → publikacja; sprawdzanie tokenu
├── JsonClient             # Klient HTTP (GET/POST/cURL)
├── GdImageHelper          # Helpery GD (tekst, tło, ikony, pobieranie obrazków, tłumaczenie, zapis)
├── Layout\*               # Komponenty do składania slajdów
└── Console                # Kolorowy output w terminalu
```

### Przepływ

```
run.php
  └─► StoryRunner::run()
        ├─► checkTokenStatus()                       (tylko gdy DEBUG=false)
        └─► foreach moduł:
              ├─► StoryModule::run() → StoryPayload
              └─► foreach slajd → InstagramPublisher::publish($url)
                                  (pomijane w DEBUG i przy nieważnym tokenie)
```

Błąd w jednym module (albo pluginie) jest logowany i pomijany — nie przerywa pozostałych.

### Layout

Każdy komponent (`Layout\Component`) dostaje prostokąt `Bounds`, rysuje się w nim i zwraca zajętą wysokość.

| Komponent | Do czego |
|---|---|
| `Slide` | Szkielet Story: tło, tytuł (+ podtytuł), obszar treści, stopka, zapis do pliku |
| `Stack` | Układa dzieci jedno pod drugim; każde dostaje resztę wolnego miejsca |
| `VerticalSplit` | Dzieli obszar na równe sloty (pluginy w `NewsModule`, połówki w `KosmosModule`) |
| `Text`, `WrappedText` | Tekst w jednej linii (left/center/right) i tekst zawijany |
| `Stat` | Wycentrowana etykieta + duża liczba |
| `Photo` | Zdjęcie z sieci, skalowane do dostępnego miejsca, z podpisem i opisem |
| `LineChart` | Wykres liniowy z wypełnieniem; oś X proporcjonalna do czasu |
| `BarChart` | Poziomy wykres słupkowy z ikonami; miejsce na podpisy liczone z ich szerokości |
| `Separator`, `Spacer` | Linia pozioma / pusty odstęp |
| `Custom` | Furtka na rysowanie niestandardowe (np. mapa pogody) |

```php
$body = (new VerticalSplit())
    ->add((new Stack())
        ->add(new Text('Nagłówek', size: 28, color: '#FFFFFF', font: FONT_BOLD))
        ->add(new WrappedText('Dłuższy opis…', size: 22, color: '#AAAAAA', font: FONT, lineHeight: 30)))
    ->add(new Photo($imageUrl, caption: 'Podpis'));   // zdjęcie wypełnia dolną połowę

[$path, $url] = (new Slide('TYTUŁ', footer: Slide::source('Źródło: …')))->render($body)->save('nazwa');
```

---

## Moduły (`StoryModule`)

### `PalestineModule` — GAZA
Codzienny raport z [Tech for Palestine](https://data.techforpalestine.org/): liczba zabitych danego dnia, suma łączna, porównanie z poprzednim raportem, podział na dzieci/kobiety/dziennikarzy. Tło z kropek proporcjonalne do liczby ofiar. Gdy brak raportu z wczoraj — pokazuje ostatni dostępny.

### `NewsModule` — ŚWIAT
Dzieli planszę na `PLUGINS_PER_SLIDE` (2) równe sloty i rysuje w nich pluginy wylosowane na dany dzień (seed = data, więc kolejność zmienia się codziennie). Aktywne: `BitcoinPlugin`, `PopulationPlugin`. `DisasterPlugin` jest wyłączony.

### `KosmosModule` — KOSMOS
Górna połowa: asteroidy z wczoraj (dane i komponent z `AsteroidsPlugin`) oraz dzienny fakt obalający płaską Ziemię. Dolna połowa: losowe zdjęcie z Curiosity z ostatniego roku, z podpisem przetłumaczonym na polski.

**Źródła:** NASA NeoWs, `images-api.nasa.gov`

### `WeatherModule` — POLSKA
Prognoza na 12:00 z [Open-Meteo](https://open-meteo.com/) dla 9 miast, mapa cieplna Polski (interpolacja IDW w obrysie z `geo/poland-border.php`), mediana temperatury i wilgotności oraz losowy komentarz. Etykiety miast są układane tak, by mieściły się w granicach kraju i nie nachodziły na siebie.

### `RemembranceModule` *(w przygotowaniu)*
Na razie tylko loguje, że nie ma jeszcze funkcjonalności.

---

## Pluginy (`NewsPlugin`)

Plugin rozszerza `AbstractPlugin`: implementuje `getLabel()`, `fetchData()` i `content(array $data): Component`. `NewsModule` rysuje go w przydzielonym slocie, a inny moduł może osadzić jego treść we własnym layoucie przez `$plugin->component($data)` (tak robi `KosmosModule` z asteroidami).

### `BitcoinPlugin`
Pobiera cenę i zmianę 24h z CoinGecko, dopisuje cenę do `btc_data.txt` (pomija duplikaty z tej samej minuty i nierealne skoki) i rysuje wykres **całej** zebranej historii. Na produkcji plik rośnie o jeden wpis dziennie.

### `PopulationPlugin`
Szacunkowa populacja świata (ekstrapolacja liniowa) i 10 najludniejszych krajów z flagami (World Bank API, `flagcdn.com`).

### `AsteroidsPlugin`
Asteroidy, które wczoraj minęły Ziemię (NASA NeoWs): liczba, najbliższe przejście i porównanie z odległością do Księżyca (`MoonProximityBar`). Używany przez `KosmosModule`.

### `DisasterPlugin` *(wyłączony)*
Aktywne zdarzenia z NASA EONET z dobranym zdjęciem — wyłączony, bo nie da się trafnie dobrać zdjęć.

---

## Tworzenie własnego modułu

```php
class MojModul implements StoryModule
{
    public function getLabel(): string { return 'Mój moduł'; }

    public function run(): StoryPayload
    {
        $body = (new Stack(gap: 40))->add(new Stat('Odpowiedź', '42'));

        [$path, $url] = (new Slide('MÓJ MODUŁ', footer: Slide::source('Źródło: ja')))
            ->render($body)
            ->save('moj-modul');

        return StoryPayload::single($path, $url, $this->getLabel());
    }
}
```

Moduł, który danego dnia nie ma nic do opublikowania, zwraca `new StoryPayload([], $this->getLabel())`. Rejestracja: tablica `$modules` w `run.php`.

## Tworzenie własnego pluginu

```php
class MojPlugin extends AbstractPlugin
{
    public function getLabel(): string { return 'Mój plugin'; }

    public function fetchData(): array
    {
        return ['value' => 123];   // pobranie danych, tłumaczenia itp.
    }

    protected function content(array $data): Component
    {
        return (new Stack())
            ->add(new Spacer(30))
            ->add(new Text('Mój plugin', size: 24, color: '#8899CC', font: FONT, height: 40))
            ->add(new Text((string) $data['value'], size: 48, color: '#FFFFFF', font: FONT_BOLD, height: 70));
    }
}
```

Rejestracja: `NewsModule::$availablePlugins`. Nagłówek w stylu jak wyżej (niebieska etykieta 24 px + wartość 48 px) trzyma spójność z pozostałymi pluginami na planszy ŚWIAT.

---

## Struktura katalogów

```
IGAPI/
├── run.php                   # Punkt wejścia
├── token.php                 # Generowanie nie wygasającego tokenu strony Meta
├── deploy.sh                 # Deploy na hosting
├── .env / .env.example
├── btc_data.txt              # Historia cen BTC (BitcoinPlugin)
├── Inter-Regular.ttf, Inter-Bold.ttf
├── geo/poland-border.php     # Obrys Polski (WeatherModule)
├── ig-stories/               # Obrazki z trybu debug
└── src/
    ├── Contracts/   StoryModule, StoryPayload, StoryRunner, NewsPlugin, AbstractPlugin, Bounds
    ├── Helper/      Console, GdImageHelper
    ├── Http/        JsonClient, InstagramPublisher
    ├── Layout/      Component, Slide, Stack, VerticalSplit, Text, WrappedText, Stat, Photo,
    │                LineChart, BarChart, Separator, Spacer, Custom
    ├── Modules/     PalestineModule, NewsModule, KosmosModule, WeatherModule, RemembranceModule
    └── Plugins/     BitcoinPlugin, PopulationPlugin, AsteroidsPlugin, DisasterPlugin, MoonProximityBar
```
