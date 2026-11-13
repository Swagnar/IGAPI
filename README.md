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

Kolejność i to, które slajdy są publikowane, ustala `stories.json` (w edytorze: „Plan publikacji”).

| Slajd | Moduł | Co pokazuje |
|---|---|---|
| **GAZA** | `PalestineModule` | Dzienny raport ofiar z Tech for Palestine |
| **ŚWIAT** | `slides/swiat.json` | 2 losowe pluginy z puli: Bitcoin, populacja, trzęsienia ziemi, ceny prądu w Europie, lód morski, kursy walut, inflacja, ceny paliw i inne |
| **KOSMOS** | `slides/kosmos.json` | Wczorajsze asteroidy, fakt dla płaskoziemców, zdjęcie z Curiosity |
| **POLSKA** | `slides/polska.json` | Mapa cieplna pogody w 9 miastach + na zmianę polskie gry na Steamie (lista jak w sklepie: miniaturka, cena, wykres 30 dni, trend) albo wszystkie wczorajsze loty nad Polską, albo szanse na grzyby w lasach (w sezonie) |

---

## Funkcje

- Generowanie grafik GD z komponentów layoutu (`Stack`, `VerticalSplit`, `Photo`, wykresy…) zamiast ręcznie liczonych współrzędnych
- Publikacja przez Meta Graph API (z oczekiwaniem na gotowość kontenera)
- Sprawdzanie tokenu Meta przed każdym uruchomieniem — z datą wygaśnięcia i ostrzeżeniem z wyprzedzeniem
- Przy nieważnym tokenie slajdy i historia (np. ceny BTC) nadal się generują — pomijana jest tylko publikacja
- Deterministyczny, dzienny dobór treści (seed = data) — kilka uruchomień tego samego dnia daje to samo
- Tryb `DEBUG` do lokalnego testowania bez publikacji (domyślny, gdy `.env` nie mówi inaczej)
- Tłumaczenie opisów zdjęć NASA na polski
- Slajdy opisane w JSON (`slides/`) z lokalnym edytorem i podglądem na żywo
- Cache odpowiedzi API — przy awarii źródła slajd używa ostatnich danych albo pomija sekcję
- `deploy.sh` — jedno polecenie do wdrożenia na hosting

---

## Wymagania

- PHP 8.1+ z rozszerzeniami `gd`, `curl`, `mbstring`
- Composer
- Konto Instagram Business podpięte do strony na Facebooku + aplikacja Meta
- Klucz API NASA ([api.nasa.gov](https://api.nasa.gov/)) — dla asteroid na slajdzie KOSMOS

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
| `PLK_API_KEY` | nie | Klucz Otwartych Danych Kolejowych PKP PLK (darmowy, wniosek na pdp-api.plk-sa.pl) — bez niego blok `trains` nie ma danych |
| `APP_ID`, `APP_SECRET` | zalecane | Dane aplikacji Meta — potrzebne do `token.php` i do sprawdzania daty wygaśnięcia tokenu |
| `DEBUG` | nie | `false` na produkcji. **Brak wpisu = tryb debug** (bez publikacji), żeby lokalny run niczego nie wrzucił |
| `PUBLIC_DIR`, `PUBLIC_URL` | nie | Gdzie zapisywać obrazki na produkcji i pod jakim URL-em są dostępne. Domyślnie `../swagnar.dev/ig-stories` i `https://swagnar.dev/ig-stories` |
| `WEB_DIR` | nie | Document root strony swagnar.dev — tam run zapisuje `data/today.json` i rendery. Domyślnie `../swagnar.dev` (w trybie debug: `web/`) |

---

## Uruchomienie

```bash
php run.php
```

W trybie debug obrazki trafiają do lokalnego `ig-stories/`, a publikacja jest pomijana. Na produkcji (`DEBUG=false`) obrazki zapisywane są w `PUBLIC_DIR` — musi to być katalog w document root domeny z `PUBLIC_URL`, bo Instagram pobiera obrazki po URL-u.

### Edytor slajdów (lokalnie)

```bash
composer admin        # albo: php -d xdebug.mode=off -S 127.0.0.1:8080 admin/router.php
```

Otwórz http://127.0.0.1:8080. Edytor działa tylko na localhost i nie jest wysyłany na serwer.

- **nowy slajd** — „+ Nowy slajd” tworzy `slides/<nazwa>.json` ze szkieletem i dopisuje go do planu jako **wyłączony**
- **plan publikacji** — kolejność (↑ ↓) i włączanie slajdów, zapis od razu do `stories.json`; lista slajdów pokazuje ● publikowany, ○ wyłączony, — poza planem
- **drzewo bloków** — dodawanie, przeciąganie (górna/dolna połowa wiersza = przed/za, dolna połowa kontenera = do środka), ↑ ↓, duplikowanie, usuwanie (Delete)
- **przenoszenie między slajdami** — we właściwościach bloku „Przenieś do slajdu”: wybór kontenera w innym slajdzie (np. „POLSKA › VerticalSplit › Pula”), „Przenieś” dopisuje blok na koniec kontenera i usuwa go z bieżącego slajdu (zapisuje oba pliki), „Kopiuj” tylko dopisuje
- **podgląd** — renderowany przez ten sam silnik GD co produkcja, z danych w `cache/`; kliknięcie w podgląd zaznacza blok
- **właściwości** — pola z parametrów konstruktora komponentu (refleksja), kolory z pipetą, `when`/`order`/`name`, teksty zależne od wartości (`variants`)
- **dane** — wartości ze źródeł slajdu; kliknięcie wstawia `{{źródło.pole}}` w aktywne pole
- **Integracje** — wszystkie źródła: status, wiek danych, które slajdy ich używają, endpoint i parametry, „Pobierz teraz”, dane dla szablonu i nagrane odpowiedzi API (klucze ukryte); w edytorze `BitcoinPlugin` nie dopisuje cen do `btc_data.txt`
- **Ctrl+S** zapis, **Ctrl+Z / Ctrl+Shift+Z** cofanie i ponawianie, błędy w szablonie podświetlają blok
- zapis zachowuje format plików (liście w jednej linii, stała kolejność kluczy), więc diff pokazuje tylko zmienione bloki

Edytor i Claude Code w terminalu pracują na tych samych plikach. Zmiana `slides/*.json` na dysku jest wczytywana automatycznie; jeśli w edytorze są niezapisane zmiany, pojawia się baner z wyborem. Zaznaczony blok edytor zapisuje w `.igapi/selection.json` (ścieżka, treść węzła, niezapisane zmiany, błąd podglądu) — wystarczy w terminalu napisać „zmień zaznaczony blok…”.

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
./deploy.sh          # wysyła kod (src/, geo/, run.php, …), robi composer install --no-dev i wgrywa stronę web/
./deploy.sh --run    # to samo + próbne uruchomienie na serwerze w trybie debug
```

`deploy.sh` nie rusza danych produkcyjnych: `.env`, `btc_data.txt`, wygenerowanych obrazków i `data/` strony (eksport porannego runu). Stronę wgrywa tam, gdzie zapisuje ją `run.php`: do `WEB_DIR` z `.env` na serwerze, a bez tego wpisu do `~/swagnar.dev`. Pliki strony są nadpisywane, nic nie jest kasowane. Ścieżki można nadpisać zmiennymi `SSH_HOST`, `SSH_KEY`, `REMOTE_DIR`, `PUBLIC_DIR`, `WEB_DIR`, `PHP_BIN`.

**Harmonogram** — zadanie CRON w cPanelu, codziennie o 06:00 (czas serwera = CET):

```
0 6 * * * /usr/local/bin/php /home/vh13519/IGAPI/run.php >> /home/vh13519/IGAPI/cron.log 2>&1
```

Log z każdego uruchomienia trafia do `~/IGAPI/cron.log`.

**Strona swagnar.dev** — pliki z `web/` wgrywa `deploy.sh` do document root domeny (`WEB_DIR`, domyślnie `~/swagnar.dev`). Każdy run dopisuje tam `data/today.json` i rendery (`data/slides/*.webp`, `data/plugins/*.webp`), więc strona pokazuje dzisiejsze story i wszystkie pluginy bez osobnego zadania CRON.

Drugie zadanie zbiera przez całą dobę próbki samolotów nad Polską (`cache/planes/<data>.jsonl`, ~1 MB dziennie, trzymane 7 dni) — z nich `PlanesPlugin` składa o 6:00 loty z poprzedniego dnia. Bez niego blok samolotów się pomija:

```
*/5 * * * * /usr/local/bin/php /home/vh13519/IGAPI/collect-planes.php > /dev/null 2>&1
```

---

## Architektura

```
run.php                   # Punkt wejścia — moduły według planu ze stories.json
├── Playlist               # stories.json → włączone moduły (kod PHP albo slides/*.json) w kolejności
├── bootstrap.php          # Autoload, .env, stałe (wspólne z edytorem)
├── TemplateModule         # Slajd z slides/*.json: dane ze źródeł → ComponentFactory → Slide
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
  ├─► 1. StoryRunner::run()
  │     ├─► checkTokenStatus()                       (tylko gdy DEBUG=false)
  │     └─► foreach moduł:
  │           ├─► StoryModule::run() → StoryPayload  (dane tylko wylosowanych pluginów)
  │           └─► foreach slajd → InstagramPublisher::publish($url)
  │                               (pomijane w DEBUG i przy nieważnym tokenie)
  ├─► 2. WebExport::addStory() → write()             (dzisiejsze slajdy i ich pluginy na WWW)
  └─► 3. WebExport::addRemaining() → write()         (pobiera dane pozostałych pluginów i renderuje ich bloki)
```

Dane źródeł trzyma wspólny `SourceStore`: źródło pobiera się dopiero, gdy blok po nie sięgnie, i tylko raz na run. Pula `pick` sprawdza kandydatów po kolei aż do skompletowania N, więc o 6:00 najpierw idą zapytania potrzebne do story, a reszta dopiero po publikacji — na potrzeby strony.

Błąd w jednym module (albo pluginie) jest logowany i pomijany — nie przerywa pozostałych.

### Strona WWW (`web/`)

Statyczna strona z wszystkimi slajdami i pluginami (HTML/CSS/JS, bez builda). Opisy i ikony pluginów są w `web/app.js`, a stan dnia czyta z `data/today.json`, który zapisuje `Web\WebExport`:

| Status pluginu | Znaczenie | Na stronie |
|---|---|---|
| `story` | narysowany na dzisiejszym story | niebieski, „dziś” |
| `ready` | ma dane, dziś poza story | bez oznaczenia |
| `empty` | nie ma dziś czego pokazać (`skip()` albo `hasContent()` = false, np. zorza przy słabym Kp) | czerwony, „brak danych” |
| `failed` | źródło nie zwróciło danych | czerwony, „błąd źródła” |

Każdy plugin ma render swojego bloku w jego rozmiarze (`size`, 1/4–4/4 slajdu) — tak, jak wygląda na story. Komunikaty błędów API nie trafiają do JSON-a. Lokalnie (`DEBUG`) eksport idzie do `web/data/` (poza repo); podgląd: `php -S 127.0.0.1:8090 -t web`.

### Layout

Każdy komponent (`Layout\Component`) dostaje prostokąt `Bounds`, rysuje się w nim i zwraca zajętą wysokość.

| Komponent | Do czego |
|---|---|
| `Slide` | Szkielet Story: tło, tytuł (+ podtytuł), obszar treści, stopka, zapis do pliku |
| `Theme` | Wygląd slajdów: tło „zorza” (gwiazdy i rozmyte pasma) w kolorach slajdu — ŚWIAT niebieski, KOSMOS fioletowy, POLSKA czerwono-pomarańczowy — i szklane karty z paskiem akcentu |
| `Card` | Szklana karta pod blokiem; `ComponentFactory` owija nią każdy plugin wstawiony wprost w slajd |
| `Stack` | Układa dzieci jedno pod drugim; każde dostaje resztę wolnego miejsca |
| `VerticalSplit` | Dzieli obszar na sloty pod sobą: dziecko z rozmiarem (ćwiartki, `"size"` pluginu) dostaje stały slot `VerticalSplit::slot()` — przy treści slajdu 1/4 = 387, 2/4 = 815, 3/4 = 1242, 4/4 = 1670 px; nieużyte ćwiartki zostają puste na dole, dzieci bez rozmiaru dzielą resztę po równo |
| `WorldMap` | Mapa świata w odwzorowaniu Equal Earth z obrysem globu, siatką, granicami i znacznikami (`geo/world.json`, Natural Earth 1:110m); z `inset` globus się zmniejsza, a w rogu pojawia się przybliżenie wskazanego miejsca |
| `Table` | Wiersze z kolumnami wyrównanymi w pionie; kolumna `flex` zabiera resztę szerokości, `align` per kolumna, `bold` dla wybranych kolumn |
| `MoonBar` | Liniowa skala odległości od Ziemi w odległościach Księżyca, od zera do okrągłej wartości tuż za asteroidą (prawdziwe proporcje: przy 69× Księżyc stoi tuż przy Ziemi); podziałki co okrągły krok, Księżyc zawsze podpisany, satelity GEO gdy jest miejsce; znacznik asteroidy z podpisem |
| `Columns` | Dzieci obok siebie, szerokości wg `weights` (np. mapa i ranking w `ElectricityPlugin`) |
| `HeatMap` | Mapa cieplna obszaru: interpolacja IDW w wielokątach, paleta z przezroczystością, etykiety omijające się nawzajem; odwzorowanie `plate` (Polska) albo `laea` (Europa); kontury `area`/`land` także po nazwie: `poland`, `europe:PL,DE`, `europe:!PL,DE`. Dodatkowo `paths` — ślady (np. przelot ISS, trasy samolotów) z wyróżnioną częścią, grubością i przezroczystością (`width`, `alpha`), strzałką (biała albo w kolorze `core`) i numerem — oraz `glow` — zorza: pionowe kurtyny od północy do zadanej szerokości |
| `Text`, `WrappedText` | Tekst w jednej linii (left/center/right) i tekst zawijany |
| `Stat` | Wycentrowana etykieta + duża liczba |
| `Photo` | Zdjęcie z sieci, skalowane do dostępnego miejsca, z podpisem i opisem |
| `LineChart` | Wykres liniowy z wypełnieniem; oś X proporcjonalna do czasu; w szablonie formaty jako tekst: `formatY: "${v}"`, `formatX: "d.m.Y"`, `decimals` (miejsca po przecinku w `{v}`, np. 2 dla kursów) |
| `BarChart` | Poziomy wykres słupkowy z ikonami; miejsce na podpisy liczone z ich szerokości; `color` w wierszu wyróżnia słupek |
| `ColumnChart` | Pionowe słupki od linii zera, każdy we własnym kolorze (średnie roczne temperatury w barwach „warming stripes”); podpisy osi X przy słupkach z `tick`, siatka Y w okrągłych krokach, `formatY: "{v}°C"`; znak „+” tylko na osi z wartościami ujemnymi |
| `SolarSystem` | Układ Słoneczny w rzucie 3D w prawdziwej skali (au): ekliptyka z okręgami co 50 au, orbity planet, heliopauza, trajektorie sond z kreską do płaszczyzny (pod ekliptyką przygaszone); planety opcjonalnie z prawdziwą orbitą (`orbit`, punkty `[x, y, z]`), pozycją `pos`, kolorem, wielkością i podpisem (`labeled`, podpisy omijają się nawzajem); kamera `elevation`, `azimuth` (domyślnie sondy w prawo), okręgi `rings`, `heliopause` (0 = bez), `radial` — `linear` (prawdziwa skala) albo `sqrt` / `log` (odległości ściśnięte, żeby zmieścić Merkurego i Neptuna) |
| `Gauge` | Licznik-półkole: pas podzielony na strefy (`zones`: `to`, `color`), wskazówka, wartość i podpis pod osią; strefa ze wskazówką w pełnym kolorze, reszta przygaszona; `scale: false` ukrywa podpisy min/max |
| `ArcticGlobe` | Globus w rzucie ortograficznym z widokiem na biegun (Arktyka, a z `centerLat` < 0 — Antarktyda): ląd z `geo/world.json`, lód z siatki NSIDC (`grid` z `pole` north/south, każdy piksel przeliczany na komórkę rzutu polarnego), siatka co 10°/30°, linie `[lat, lon]` (np. mediana granicy lodu, przerywana) i podpisane punkty |
| `SteamList` | Lista gier w stylu sklepu Steam: miniaturka, tytuł, studio z ceną, wykres 30 dni, szczyt z doby i trend |
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
Codzienny raport z [Tech for Palestine](https://data.techforpalestine.org/): liczba zabitych danego dnia, suma łączna, porównanie z poprzednim raportem, liczba dzieci i kobiet szacowana z udziału w ostatnim raporcie z podziałem (jak na stronie Tech for Palestine) i dziennikarze, o ile ich liczba zmieniła się w ciągu ostatnich 30 dni. Tło z kropek proporcjonalne do liczby ofiar. Gdy brak raportu z wczoraj — pokazuje ostatni dostępny.

### `slides/swiat.json` — ŚWIAT
Slajd z szablonu: `VerticalSplit` z pulą węzłów `Plugin` (`bitcoin`, `population`, `earthquakes`, `electricity`, `climate`) i `"pick": 2`, w puli też `bigMac`, `fearGreed`, `seaIce`, `currencies`, `inflation`, `fuel` oraz ćwiartkowe `elNino` i `housing` — każdego dnia losowane są pluginy na całe 4/4 (seed = data): dwa po 2/4 albo 2/4 + dwa po 1/4. Nowy plugin wystarczy dopisać do puli i do `sources`. Dane pobierane są dla całej puli, więc historia BTC rośnie także w dni, gdy Bitcoina nie ma na slajdzie. `DisasterPlugin` jest wyłączony.

### `slides/kosmos.json` — KOSMOS
Slajd z szablonu: `VerticalSplit` jak na POLSCE i ŚWIECIE (zob. [Pluginy w JSON](#pluginy-w-json)). U dołu ćwiartka (`"size": 1`): plugin `asteroids` — asteroidy z wczoraj i skala odległości `MoonBar`. Nad nią pula (`VerticalSplit` „Pula”, `"pick": 1`) na pozostałe 3/4, która codziennie losuje bloki, aż wypełni te 3/4: `curiosity` (1/4–4/4 zależnie od proporcji zdjęcia) — losowe zdjęcie z Curiosity z ostatniego roku, z podpisem po polsku; `launch` — najbliższy start rakiety; `iss` — widoczne przeloty ISS (w dni bez przelotu pomijany, losuje się inny); `satellites` — działające satelity na orbicie; `voyager` — gdzie są Voyagery (rzut 3D); `planets` (3/4) — układ planet dziś (rzut 3D wszystkich planet, widoczność na niebie, najbliższa opozycja); `solarCycle` — plamy na Słońcu i cykl słoneczny; `flatEarth` — dzienny fakt obalający płaską Ziemię (`data/flat_earth_facts.json`).

**Źródła:** `asteroids` (NASA NeoWs), `curiosity` (`images-api.nasa.gov`), `flatEarth` (plik lokalny), `launch` (Launch Library 2), `iss` i `satellites` (CelesTrak), `voyager` (JPL Horizons), `planets` (obliczenia lokalne z elementów orbit JPL), `solarCycle` (NOAA SWPC)

### `slides/polska.json` — POLSKA
Slajd z szablonu: `VerticalSplit` — u góry zawsze `weather` (`WeatherPlugin`), na dole pula (`VerticalSplit` „Pula”, `"pick": 1`, wypełnia pozostałe 2/4) z `polishGames` (`PolishGamesPlugin`), `planes` (`PlanesPlugin`), `mushrooms` (`MushroomsPlugin`, w sezonie) oraz ćwiartkowe `doctors` (`DoctorsPlugin`), `avalanche` (`AvalanchePlugin`, tylko zimą) i `trains` (`TrainsPlugin`). Plugin pogody korzysta też ze źródła `aurora`: przy prognozie Kp ≥ 5 na mapie pojawia się zorza.

### `RemembranceModule` *(w przygotowaniu)*
Na razie tylko loguje, że nie ma jeszcze funkcjonalności.

---

## Pluginy (`NewsPlugin`)

Plugin to **dane w PHP + wygląd w JSON**. Klasa rozszerza `AbstractPlugin`: `fetchData()` pobiera surowe dane (trafiają do cache), a `present()` dolicza z nich pola dla szablonu — sformatowane liczby, kolory, wiersze wykresów. Wygląd jest w `plugins/<id>.json` i edytuje się go w edytorze blok po bloku (zob. [Pluginy w JSON](#pluginy-w-json)). Rejestracja: `PluginSource` w `Data\Sources::all()`; w slajdzie węzeł `{ "type": "Plugin", "plugin": "<id>" }`. Pola z `present()` są liczone przy rysowaniu, więc zmiana wyglądu nie wymaga ponownego pobrania danych. `content()` z wyglądem w PHP zostało tylko dla zgodności (`"source"`).

### `BitcoinPlugin`
Pobiera cenę i zmianę 24h z CoinGecko, dopisuje cenę do `btc_data.txt` (pomija duplikaty z tej samej minuty i nierealne skoki) i rysuje wykres **całej** zebranej historii. Na produkcji plik rośnie o jeden wpis dziennie.

### `PopulationPlugin`
Szacunkowa populacja świata (ekstrapolacja liniowa) i 10 najludniejszych krajów z flagami (World Bank API, `flagcdn.com`).

### `EarthquakesPlugin`
Trzęsienia ziemi M4,5+ z ostatniej doby ([USGS](https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php)) na mapie świata `WorldMap`: liczba, znaczniki skalowane magnitudą (żółty < 5, pomarańczowy < 6, czerwony ≥ 6), przybliżenie rejonu najsilniejszego w rogu mapy i tabela trzech najsilniejszych (nr, magnituda, region po polsku, głębokość). Źródło `earthquakes`.

### `ElectricityPlugin`
Hurtowe ceny prądu na dziś (rynek dnia następnego) w ~35 strefach cenowych Europy: mapa cieplna w stylu mapy pogody (`HeatMap`, odwzorowanie Lamberta, kolorowane tylko kraje z danymi), cena w Polsce w zł/kWh (kurs EUR z NBP) i €/MWh, miejsce w rankingu, najtańsze/najdroższe kraje oraz najtańsza i najdroższa godzina w Polsce. Dane: [Nord Pool](https://data.nordpoolgroup.com/) (22 strefy jednym zapytaniem — to samo API, z którego korzysta ich strona) + [Energy-Charts](https://api.energy-charts.info/) dla Hiszpanii, Portugalii, Włoch, Szwajcarii, Czech, Słowacji, Węgier, Słowenii, Chorwacji, Grecji i Serbii. Energy-Charts ma limit ~3 zapytań na 30 s, więc pobieranie trwa 1,5–2,5 min. Źródło `electricity`.

### `WeatherPlugin`
Prognoza na 12:00 z [Open-Meteo](https://open-meteo.com/) dla 9 miast, mapa cieplna Polski (`HeatMap`: interpolacja IDW w obrysie z `geo/poland-border.php`), mediana temperatury i wilgotności, odczuwalna temperatura i komentarz zależny od temperatury — zakresy i teksty są w `plugins/weather.json` (`variants` bloku „Nastrój”), edytowalne w edytorze; tekst losowany raz dziennie. Źródło `weather`.

### `PolishGamesPlugin`
Polskie gry z `data/polish_games.json` (numery aplikacji sprawdzone w sklepie Steam) jako lista w stylu sklepu Steam: miniaturka, tytuł, studio z ceną w zł (przy promocji zielona plakietka z rabatem), wykres dziennych szczytów graczy z 30 dni, szczyt z ostatniej doby i trend tydzień do tygodnia. Gra z największym wzrostem tygodnia (≥ 10%) ma wyróżniony trend. Wierszy jest tyle, ile zmieści się w slocie.

Historia graczy pochodzi z `chart-data.json` na [steamcharts.com](https://steamcharts.com/) (punkty godzinowe z ostatniego miesiąca) — Steam nie podaje jej w API. Gdy jej zabraknie — liczba graczy w tej chwili z oficjalnego API Steama, oznaczona „gra teraz”, bez wykresu. Ceny: jedno zapytanie `appdetails` dla wszystkich gier; miniaturki: adres z `appdetails` (nowe gry mają w nim hash). Pobieranie ~20 s. Źródło `polishGames`.

### `ClimatePlugin`
Temperatura Ziemi z [Copernicus Climate Pulse](https://pulse.climate.copernicus.eu/) (ERA5, CSV od 1940): średnia globalna z ostatniego dostępnego dnia (2–3 dni opóźnienia), odchylenie od normy 1991–2020 i od epoki przedprzemysłowej (+0,88 °C wg C3S) oraz wykres `ColumnChart` średnich rocznych odchyleń w barwach „warming stripes” — bieżący rok niepełny, z gwiazdką. Źródło `climate`.

### `LaunchPlugin`
Najbliższy start rakiety z [Launch Library 2](https://thespacedevs.com/llapi) (tylko statusy Go / TBC, czyli z pewną datą): rakieta, misja i operator, kiedy po polsku („jutro o 03:49 · za 21 h”), skąd, zdjęcie, opis misji po polsku i trzy kolejne starty. Limit API ~15 zapytań/h, dzienny cache wystarcza. Źródło `launch`.

### `IssPlugin`
Widoczne przeloty ISS w ciągu doby od publikacji, liczone lokalnie z aktualnego TLE z [CelesTrak](https://celestrak.org/) (`Helper\Orbit` — uproszczone SGP4, błąd ~10 km w ciągu 1,5 doby od epoki). Widoczny = ISS w słońcu, u obserwatora (środek Polski) ciemno, ≥ 20° nad horyzontem. Godziny, kierunek na niebie, maksymalna wysokość i mapa z jasnym odcinkiem widocznej części (`HeatMap` z `paths`, zasięg mapy dopasowany do śladu). Przeloty widać seriami po 1–3 tygodnie, w pozostałe dni plugin zwraca `skip()`. Źródło `iss`.

### `AuroraPlugin`
Szansa na zorzę polarną: najwyższy prognozowany indeks Kp ([NOAA SWPC](https://www.swpc.noaa.gov/)) w nadchodzącą noc (16:00–04:00). Nie ma własnego bloku — przy Kp ≥ 5 dokłada zorzę (`glow`, pionowe kurtyny) na mapę pogody (od północy do szerokości, z której zorzę widać nisko nad horyzontem, ~2° na każdy punkt Kp) i linię tekstu („widać ją aż po Poznań i Warszawę”). Źródło `aurora`.

### `BigMacPlugin`
Indeks Big Maca ([The Economist](https://github.com/TheEconomist/big-mac-data), CSV na GitHubie, nowe wydanie w styczniu i lipcu): cena w Polsce, ile to w dolarach i czy złoty jest według indeksu zaniżony lub zawyżony wobec dolara i euro (±3% = „uczciwie”), zmiana ceny rok do roku i `BarChart` cen w dolarach w 10 krajach z Polską wyróżnioną na żółto. Źródło `bigMac`.

### `SatellitesPlugin`
Działające satelity z katalogu SATCAT ([CelesTrak](https://celestrak.org/), grupa `active`, ~6 MB — do cache trafiają tylko sumy): liczba, udział Starlinka, przyrost w 30 dni, polskie satelity z ostatnim wystrzelonym, `ColumnChart` roku startu wciąż działających satelitów i tabele największych sieci i właścicieli. Źródło `satellites`.

### `VoyagerPlugin`
Gdzie są Voyagery: trajektorie obu sond od 1977 co 90 dni z [JPL Horizons](https://ssd.jpl.nasa.gov/horizons/) (wektory heliocentryczne, ekliptyka J2000) + dzisiejsza odległość od Ziemi, czas biegu sygnału i prędkość. Rysuje je `SolarSystem` z planetami zewnętrznymi (pozycje z długości średniej J2000) i miejscami przekroczenia heliopauzy (V1 2012, V2 2018). 6 zapytań, ~5 s. Źródło `voyager`.

### `FearGreedPlugin`
Indeks strachu i chciwości rynku kryptowalut ([alternative.me](https://alternative.me/crypto/fear-and-greed-index/), bez klucza): dzisiejsza wartość 0–100 na `Gauge` z pięcioma strefami, porównanie z wczoraj / tydzień / miesiąc / rok temu i `ColumnChart` 90 dni w kolorach stref. Indeks CNN dla akcji blokuje zapytania spoza przeglądarki. Źródło `fearGreed`.

### `SeaIcePlugin`
Lód morski w Arktyce i wokół Antarktydy z [NSIDC Sea Ice Index](https://nsidc.org/data/g02135) (v4): zasięg w mln km² z ostatniego dnia (dane z 1–2 dni opóźnieniem), różnica ze średnią 1981–2010, miejsce wśród tego samego dnia w latach od 1979 i rok temu. Do rysunku: siatka zasięgu z GeoTIFF-a (25 km, czytana bez bibliotek, do cache ~10 KB) i mediana granicy lodu 1981–2010 na ten dzień roku (shapefile w ZIP, przeliczany z rzutu polarnego na lat/lon) — osobno dla każdej półkuli; rysują je dwa `ArcticGlobe` obok siebie. Źródło `seaIce`.

### `PlanetsPlugin`
Układ planet dziś: położenia ośmiu planet liczone lokalnie z elementów orbit Keplera ([JPL, Approximate Positions of the Planets](https://ssd.jpl.nasa.gov/planets/approx_pos.html), tabela 1, 1800–2050; zgodność z Horizons ~0,01°) — bez zapytań do sieci. Do tego odległość od Ziemi, pora widoczności na niebie z elongacji (przy Słońcu / wieczorem / rano / całą noc) i najbliższa opozycja Marsa, Jowisza lub Saturna. Rysuje go jeden `SolarSystem` ze wszystkimi ośmioma planetami (prawdziwe elipsy z nachyleniem, odległości ściśnięte logarytmicznie — `radial: log`, Ziemia z przodu kadru). Źródło `planets`.

### `SolarCyclePlugin`
Cykl słoneczny: miesięczna liczba plam na Słońcu z [NOAA SWPC](https://www.swpc.noaa.gov/products/solar-cycle-progression) (dane SILSO) od minimum cyklu 24 (2008) i prognoza NOAA do 2030; szczyt cyklu liczony z wygładzonej liczby plam. `ColumnChart`, prognoza szarymi słupkami. Źródło `solarCycle`.

### `CurrenciesPlugin`
Koszyk walut: średnie kursy [NBP](https://api.nbp.pl/) (tabela A, 255 notowań) dla EUR, USD, CHF, GBP, CZK i UAH — kurs, zmiana od poprzedniego notowania i w rok, waluta, która najmocniej się ruszyła, i wykres roku (`charts.<KOD>`, domyślnie dolar). Źródło `currencies`.

### `InflationPlugin`
Inflacja w Polsce: HICP r/r z [Eurostatu](https://ec.europa.eu/eurostat/) — od 2026 zbiór `prc_hicp_minr` (ECOICOP 2), wcześniejsze miesiące z `prc_hicp_manr`. Kategorie, które najmocniej drożeją, porównanie z UE i sąsiadami, 24 miesiące na `ColumnChart` w kolorach względem celu NBP (2,5% ± 1). HICP różni się od krajowego CPI GUS o kilka dziesiątych; CPI z API GUS (SDP) ma od 2026 nową, nieopisaną zmienną. Źródło `inflation`.

### `FuelPlugin`
Ceny paliw: średnie ceny detaliczne Pb95, ON i LPG z cotygodniowego [Biuletynu naftowego KE](https://energy.ec.europa.eu/data-and-analysis/weekly-oil-bulletin_en) — plik historii XLSX (link szukany na stronie, arkusz czytany strumieniowo `XMLReader`), EUR/1000 l przeliczane na zł/l kursem z biuletynu. Zmiana w tydzień i rok, wykres benzyny z roku i mapa Pb95 w krajach UE (`HeatMap` jak przy prądzie). Źródło `fuel`.

### `MushroomsPlugin`
Grzybobranie: szansa na grzyby (0–100 %) w 15 kompleksach leśnych, z pogody ostatnich dni z [Open-Meteo](https://open-meteo.com/) (jedno zapytanie, `past_days=14`): deszcz z 2 tygodni (≥ 50 mm = pełnia), średnia temperatura tygodnia (najlepiej 10–18°C) i przymrozki < −2°C z ostatnich 5 dni (każdy −25 %). Mapa cieplna Polski jak w pogodzie, trzy najlepsze lasy z nazwą; komentarz to `variants` po średniej szansie (`mushrooms.avgScore`). Poza sezonem (grudzień–maj) i gdy nigdzie szansa nie przekracza 5 % — `skip()`, w puli losuje się inny blok. Źródło `mushrooms`.

### `PlanesPlugin`
Wszystkie loty nad Polską z poprzedniej doby — publikacja jest o 6:00, więc slajd pokazuje zamknięty dzień, a nie przypadkowy moment. Live API [adsb.fi](https://adsb.fi/) (open data, do użytku osobistego i niekomercyjnego) nie ma historii, więc dzień zbiera osobny CRON: `collect-planes.php` co 5 minut zapisuje samoloty w oknie wokół Polski do `cache/planes/<data>.jsonl`. O 6:00 plugin skleja próbki jednego samolotu w loty (przerwa > 20 min = nowy lot), zostawia te, które choć raz były nad Polską, i dzieli je na kategorie — wojskowe (`dbFlags`), rządowe (`PLF1…`, `SAM`), cargo (prefiksy przewoźników), śmigłowce (kategoria A7). Mapa to `HeatMap` Polski z `paths`: tysiące lotów cienkimi, mocno przezroczystymi liniami (gęstość rysuje korytarze powietrzne), wojskowe i rządowe w kolorze ze strzałką; pod mapą najwięcej samolotów naraz i wykres lotów godzina po godzinie. Bez wczorajszego pliku albo gdy próbki pokrywają < 80 % doby — `skip()`, w puli losuje się inny blok. Źródło `planes`.

### `ElNinoPlugin`
El Niño (1/4): tygodniowa anomalia temperatury oceanu w regionie Niño 3.4 z [NOAA CPC](https://www.cpc.ncep.noaa.gov/data/indices/) (`wksst9120.for`, norma 1991–2020) — faza wg progów NOAA, porównanie z rokiem temu, od kiedy nie było tak daleko od normy i `ColumnChart` z 2 lat w kolorach faz. Źródło `elNino`.

### `HousingPlugin`
Ceny mieszkań (1/4): transakcyjne ceny m² na rynku wtórnym w 17 miastach wojewódzkich z [bazy cen mieszkań NBP](https://nbp.pl/statystyka-i-sprawozdawczosc/statystyka-rynku-nieruchomosci/) (XLSX, kwartalnie) — Warszawa, zmiana w rok, najtańsze miasto i `HeatMap` Polski z ceną jako ciepłem. Źródło `housing`.

### `DoctorsPlugin`
Kolejki do lekarza (1/4): mediana średniego czasu oczekiwania na specjalistę na NFZ (przypadek stabilny) w województwach z [Terminów Leczenia NFZ](https://api.nfz.gov.pl/app-itl-api/) — specjalność zmienia się codziennie (15 specjalności, od ortopedy po chirurga ogólnego), `HeatMap` Polski z najdłuższym i najkrótszym województwem. Lista poradni (~30 stron API) trzymana tydzień w `cache/nfz/`. Źródło `doctors`.

### `AvalanchePlugin`
Lawiny w Tatrach (1/4): stopień zagrożenia 1–5 z komunikatu [TOPR](https://lawiny.topr.pl/) (JSON osadzony w stronie) na `Gauge` w kolorach skali europejskiej, zalecenie, zagrożone wystawy powyżej granicy wysokości i stopnie na kolejne dni. Tylko XI–IV i przy ważnym komunikacie, poza tym `skip()`. Źródło `avalanche`.

### `TrainsPlugin`
Opóźnienia pociągów (1/4): wczorajsze wykonanie wszystkich kursów z [Otwartych Danych Kolejowych PKP PLK](https://pdp-api.plk-sa.pl/) — odsetek pociągów, które dojechały do stacji końcowej z opóźnieniem do 5 min, średnie spóźnienie, odwołane kursy, najbardziej opóźniony pociąg (numer i nazwa z rozkładu) z trasą na `HeatMap` Polski, a pod nią średnie spóźnienie przyjazdów na stacjach z co najmniej 60 pociągami. Wymaga `PLK_API_KEY` (darmowy, pakiet Basic: 100 zapytań/h — plugin robi ok. 12). Słownik stacji trzymany miesiąc w `cache/plk/`. Źródło `trains`.

### `DisasterPlugin` *(wyłączony)*
Aktywne zdarzenia z NASA EONET z dobranym zdjęciem — wyłączony, bo nie da się trafnie dobrać zdjęć.

---

## Slajdy z JSON

Slajd może być opisany plikiem `slides/<nazwa>.json` zamiast kodem — renderuje go `TemplateModule`, a dane dostarczają źródła (`DataSource`). To podstawa pod lokalny edytor slajdów.

```json
{
  "label": "Kosmos", "title": "KOSMOS", "footer": "Źródło: {{credits}}",
  "sources": ["asteroids"],
  "body": { "type": "Stack", "children": [
    { "type": "Text", "text": "{{asteroids.header}}", "size": 28, "font": "bold", "when": "asteroids" },
    { "type": "MoonBar", "moonPercent": "{{asteroids.closest.moonPercent}}", "indent": 0, "when": "asteroids.closest" }
  ] }
}
```

- `type` — komponent z `ComponentFactory::TYPES` (`Stack`, `VerticalSplit`, `Text`, `WrappedText`, `Photo`, `Separator`, `Spacer`, `MoonBar`, `WorldMap`, `Table`); pozostałe pola to nazwane argumenty konstruktora, `font` to `regular` lub `bold`
- `children` — dzieci kontenera (`Stack`, `VerticalSplit`)
- `{{źródło.pole}}` — wartość z danych; samodzielny placeholder zachowuje typ (np. liczbę)
- filtry — `{{eur.rate|number:4}}` → `4,3873`, `{{eur.date|date:d.m}}`, `|upper`, `|lower`, `|default:—`; można łączyć: `{{a|number:0|default:brak}}`
- `when` — węzeł jest pomijany, gdy pod tą ścieżką nie ma danych (np. API nie odpowiedziało)
- `variants` — teksty zależne od wartości: `{ "by": "weather.medianTemp", "ranges": [{ "from": 30, "texts": ["Lampa", …] }, …, { "texts": ["Sanki?"] }] }`; `{{variant}}` w tekstach węzła to jeden z tekstów zakresu o największym `from` ≤ wartość (zakres bez `from` = poniżej wszystkich), losowany raz dziennie (seed = data + `by`). W edytorze: sekcja „Teksty zależne od wartości” we właściwościach bloku tekstowego — zakresy „od”, jeden tekst w linii, podświetlony dzisiejszy zakres
- `order: "daily"` — w kontenerze: kolejność dzieci losowana raz dziennie
- `pick: N` — w kontenerze: dziennie losowane N dzieci z puli (w edytorze pominięte dziś bloki są przekreślone). Na slajdzie pula ma budżet ćwiartek i ma być pełna: po N-tym losuje dalej, dopóki zostały wolne ćwiartki i jest kandydat, który się w nich zmieści — pula 3/4 może więc dać np. 2/4 + 1/4. Kandydat, po którym reszty nie da się dopełnić dalszymi z listy (np. 1/4, gdy zostają same 2/4), jest pomijany, o ile bez niego slajd da się wypełnić dokładnie. Losowanie jest ważone: `"weight"` na dziecku puli (domyślnie 1), a blok wylosowany wczoraj albo przedwczoraj ma mniejszą szansę (historia w `cache/picks.json`, zapisuje ją tylko poranny run). `TemplateModule::calendar()` liczy, co pule wylosują w kolejnych dniach
- `Plugin` — `{ "type": "Plugin", "source": "bitcoin" }` rysuje plugin PHP jego własnym layoutem; `{ "type": "Plugin", "plugin": "asteroids" }` wstawia plugin JSON z `plugins/`
- `name` — opcjonalna nazwa bloku, ignorowana przy renderowaniu
- `{{credits}}` w `footer` — dostawcy danych bloków, które dziś faktycznie są na slajdzie („NASA / Launch Library 2”), z `credit()` źródła. Niewylosowany (`pick`), pominięty (`when`, `skip()`) i wyłączony plugin nie dokłada swojego; źródło liczy się, gdy jego niepusta wartość trafiła do narysowanego bloku (np. NOAA tylko w noc z zorzą). Bez żadnego dostawcy stopka znika

Błąd w szablonie wskazuje dokładne miejsce, np. `body.children[0].children[1] (MoonBar): Unknown named parameter $moonPercentX`.

### Pluginy w JSON

Plugin JSON to grupa bloków z własnymi źródłami, zapisana w `plugins/<id>.json` — da się go wstawić w dowolny slajd (także do puli `pick` na ŚWIECIE) i edytować w edytorze jak slajd:

```json
{ "label": "Asteroidy", "sources": ["asteroids"], "body": { "type": "Stack", "when": "asteroids", "children": [ … ] } }
```

- slajd nie musi wymieniać źródeł pluginu w swoim `sources` — `TemplateModule` zbiera je z użytych pluginów
- w edytorze pluginy są w osobnej grupie listy dokumentów; podgląd pokazuje plugin w jego rozmiarze (`size`, przy `"{{…}}"` — w rozmiarze z dzisiejszych danych) u góry slajdu, a resztę jako wolne ćwiartki; rozmiar (stały 1/4–4/4 albo „z danych”) ustawia się we właściwościach pluginu
- w podglądzie slajdu plugin jest jednym blokiem — „Edytuj plugin” (albo dwuklik w drzewie) otwiera jego plik
- plugin może wstawiać inne pluginy (do 4 poziomów — głębiej to najpewniej pętla i kończy się czytelnym błędem)
- w puli z `pick` plugin JSON jest losowany tylko wtedy, gdy jego główny blok przechodzi `when`
- `"size": 1–4` — ile ćwiartek wysokości treści slajdu zajmuje plugin (brak pola = 2, połowa). Pula z `pick` losuje tylko pluginy, które mieszczą się w wolnych ćwiartkach: za duży kandydat jest pomijany, a losowanie idzie dalej. Bloki o stałym rozmiarze (np. `asteroids` = 1 na KOSMOSIE) zajmują swoje, pula dostaje resztę i wypełnia ją do końca. Pula to `VerticalSplit` — zagnieżdżony dzieli się na tyle ćwiartek, ile sam zajmuje, więc 1/4 w puli ma tę samą wysokość co 1/4 na slajdzie. Rozmiar może zależeć od danych dnia — `"size": "{{curiosity.size}}"` (zdjęcie z Curiosity bywa panoramą na 1/4 albo pionowym zdjęciem na 4/4; źródło liczy rozmiar z proporcji zdjęcia); brak danych albo wartość spoza 1–4 = 2. Węzeł Plugin w slajdzie może nadpisać rozmiar polem `size`, a dowolny inny węzeł — polem `quarters`
- `"enabled": false` wyłącza plugin wszędzie: nie rysuje się, nie losuje w `pick` (miejsce zajmuje kolejny blok) i jego źródła nie są pobierane. W edytorze: pole `enabled` we właściwościach pluginu albo „Wyłącz plugin” na bloku Plugin w slajdzie (zapis od razu); wyłączony ma w drzewie chip „wyłączony”

### Źródła danych i cache

Źródło implementuje `DataSource` (`id`, `label`, `credit`, `describe`, `fetch`, `maxFallbackAge`) i jest rejestrowane w `Data\Sources::all()`. Wynik każdego pobrania trafia do `cache/<id>.json` razem z czasem, statusem, ewentualnym błędem i nagranymi zapytaniami HTTP.

**Źródło bez PHP** — plik `sources/<id>.json` (nazwa pliku = id w szablonie):

```json
{
    "label": "NBP — średni kurs euro",
    "credit": "NBP",
    "url": "https://api.nbp.pl/api/exchangerates/rates/a/eur/",
    "params": { "format": "json" },
    "fields": { "rate": "rates.0.mid", "date": "rates.0.effectiveDate" },
    "maxFallbackAge": 259200,
    "docs": "https://api.nbp.pl/"
}
```

- `credit` — dostawca do stopki `{{credits}}`; plugin PHP podaje go w `getCredit()`
- `params` — wartości mogą używać `{{env.NAZWA}}` (z `.env`, nie trafia do edytora ani cache) i `{{date:Y-m-d}}` / `{{date:Y-m-d:-1 day}}`
- `fields` — nazwa → ścieżka w odpowiedzi (kropki, indeksy). Brak pola w odpowiedzi to błąd widoczny w Integracjach — bez `fields` źródło zwraca całą odpowiedź
- zepsuty plik nie psuje reszty: źródło pokazuje się w edytorze z błędem konfiguracji

- CRON (`DataCache::fresh`) pobiera świeże dane; gdy API zawiedzie, używa ostatnich zapisanych, o ile nie są starsze niż `maxFallbackAge()` (asteroidy: 20 h, bo opisują „wczoraj”)
- podgląd (`DataCache::cached`) czyta z cache i pyta API tylko, gdy pliku jeszcze nie ma

| Źródło | Klasa | Dane |
|---|---|---|
| `asteroids` | `AsteroidsSource` | `header`, `count`, `closest.name`, `closest.distanceText`, `closest.moonPercent` |
| `curiosity` | `CuriosityPhotoSource` | `preview` (URL), `title`, `caption` (po polsku) |
| `flatEarth` | `DailyFactSource` | `title`, `text` — wpis dnia z `data/flat_earth_facts.json` |
| `bitcoin` | `PluginSource` (`BitcoinPlugin`) | `price`, `change`, `history` |
| `population` | `PluginSource` (`PopulationPlugin`) | `population`, `countries` |
| `electricity` | `PluginSource` (`ElectricityPlugin`) | `pl` (`plnKwh`, `eurMwh`, `rank`, `of`, `hours`), `countries` (kraj → średnia), `zones[]`, `eurPln` |
| `weather` | `PluginSource` (`WeatherPlugin`) | `cities`, `medianTemp`, `medianHumidity`; z `present()`: `medianText`, `details` (wilgotność, odczuwalna), `subColor`, `points`, `palette` |
| `polishGames` | `PluginSource` (`PolishGamesPlugin`) | `games[]` (`appid`, `name`, `studio`, `peak24`, `daily[]` — szczyty z 30 dni, `trend`, `live`, `price`, `thumb`) |
| `earthquakes` | `PluginSource` (`EarthquakesPlugin`) | `count`, `summary`, `markers` i `focus` (dla `WorldMap`: `markers`, `inset`), `top` (wiersze dla `Table`, też `top[].text`), `quakes[]` |
| `climate` | `PluginSource` (`ClimatePlugin`) | `last` (`date`, `temp`, `normal`, `anomaly`), `years[]` (`year`, `anomaly`, `days`); z `present()`: `tempText`, `anomalyText`, `bars` |
| `launch` | `PluginSource` (`LaunchPlugin`) | `launches[]` (`rocket`, `mission`, `provider`, `net`, `location`, `country`, `image`, `description`); z `present()`: `whenText`, `placeText`, `nextRows` |
| `iss` | `PluginSource` (`IssPlugin`) | `passes[]` (`start`, `end`, `maxEl`, `track` — `[lon, lat, widoczny]`), w dni bez przelotu `skip()`; z `present()`: `timeText`, `paths`, `view`, `rows` |
| `aurora` | `PluginSource` (`AuroraPlugin`) | `kpMax`, `at`, `night[]`; z `present()`: `glow` i `text` (null przy Kp < 5) |
| `bigMac` | `PluginSource` (`BigMacPlugin`) | `date`, `countries[]`, `history[]`; z `present()`: `edition`, `priceText`, `usdLine`, `valueLine`, `yearLine`, `rows` |
| `satellites` | `PluginSource` (`SatellitesPlugin`) | `total`, `lastMonth`, `years`, `owners`, `constellations`, `polish[]`; z `present()`: `totalText`, `starlinkLine`, `growthLine`, `bars`, `networkRows`, `ownerRows` |
| `voyager` | `PluginSource` (`VoyagerPlugin`) | `date`, `probes` (trajektoria, km od Słońca i Ziemi, czas światła); z `present()`: `v1`/`v2` (`distance`, `signal`, `speed`), `years`, `probes`, `planets` |
| `planets` | `PluginSource` (`PlanetsPlugin`) | `date`, `positions` (x, y, z w au), `oppositions`; z `present()`: `dateText`, `event`, `azimuth`, `planets` (planety dla `SolarSystem`), `rows` |
| `fearGreed` | `PluginSource` (`FearGreedPlugin`) | `days` (data → wartość); z `present()`: `value`, `zoneName`, `zoneColor`, `zones`, `compare`, `bars` |
| `seaIce` | `PluginSource` (`SeaIcePlugin`) | `north` / `south`: `date`, `extent`, `average`, `years`, `grid`, `median`; z `present()`: `dateText` i w `north` / `south`: `name`, `extentText`, `diffText`, `rankText`, `lines`, `markers` |
| `planes` | `PluginSource` (`PlanesPlugin`) | `day`, `interval`, `coverage`, `flights[]` (hex, znak, typ, kategoria, start, koniec, ślad), `hours` (24 liczby), `busiest`; z `present()`: `countText`, `subline`, `legend`, `notableText`, `peakText`, `hourBars`, `gapNote`, `paths`, `view` — bez wczorajszych próbek `_skip` |
| `mushrooms` | `PluginSource` (`MushroomsPlugin`) | `date`, `forests` (deszcz z 14 dni, temperatura tygodnia, przymrozki); z `present()`: `bestText`, `bestColor`, `avgScore`, `conditions`, `points`, `palette`, `view` — poza sezonem / bez szans `_skip` |
| `solarCycle` | `PluginSource` (`SolarCyclePlugin`) | `observed` (miesiąc → [plamy, wygładzona]), `predicted`; z `present()`: `valueText`, `monthText`, `cycleText`, `trendText`, `bars` |
| `currencies` | `PluginSource` (`CurrenciesPlugin`) | `date`, `rates` (kod → data → kurs); z `present()`: `dateText`, `headline`, `moverText`, `moverColor`, `rows`, `charts.<KOD>` |
| `inflation` | `PluginSource` (`InflationPlugin`) | `month`, `series` (miesiąc → HICP r/r), `latest` (kategoria → kraj → [miesiąc, wartość]); z `present()`: `monthText`, `valueText`, `valueColor`, `targetText`, `bars`, `catRows`, `countryRows` |
| `fuel` | `PluginSource` (`FuelPlugin`) | `date`, `eurPln`, `europe` (kraj → EUR/1000 l), `poland` (tydzień → zł/l); z `present()`: `dateText`, `headline`, `rankText`, `rows`, `points`, `map` |
| `eur` | `HttpSource` (`sources/eur.json`) | `rate`, `date`, `currency` — przykład, nieużywany w slajdach |

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

Moduł, który danego dnia nie ma nic do opublikowania, zwraca `new StoryPayload([], $this->getLabel())`. Najpierw sprawdź, czy wystarczy slajd z JSON (edytor → „+ Nowy slajd”). Moduł w PHP rejestrujesz w `Playlist::CODE_MODULES` (id → klasa z konstruktorem `(JsonClient $http)`) i dopisujesz jego id do `stories.json`.

## Tworzenie własnego pluginu

```php
class MojPlugin extends AbstractPlugin
{
    public function getLabel(): string { return 'Mój plugin'; }

    public function fetchData(): array
    {
        return ['value' => 1234.5];   // surowe dane: pobranie, tłumaczenia, losowanie
    }

    public function present(array $data): array
    {
        return ['valueText' => number_format($data['value'], 1, ',', ' ')] + $data;   // pola dla szablonu
    }
}
```

`plugins/moj.json` — wygląd, edytowalny w edytorze:

```json
{
    "label": "Mój plugin",
    "sources": ["moj"],
    "body": {
        "type": "Stack",
        "children": [
            { "type": "Spacer", "height": 30 },
            { "type": "Text", "text": "Mój plugin", "size": 24, "color": "#8899CC", "height": 40 },
            { "type": "Text", "text": "{{moj.valueText}}", "size": 48, "font": "bold", "height": 70 }
        ]
    }
}
```

Rejestracja: `new PluginSource('moj', new MojPlugin($http))` w `Data\Sources::all()`, potem w slajdzie `{ "type": "Plugin", "plugin": "moj" }`. Nagłówek jak wyżej (niebieska etykieta 24 px + wartość 48 px) trzyma spójność z pozostałymi pluginami. Rysowanie, którego nie da się złożyć z bloków, wydziel do komponentu w `src/Layout/` i zarejestruj w `ComponentFactory::TYPES` — tak powstały `SteamList`, `HeatMap` i `WorldMap`.

---

## Struktura katalogów

```
IGAPI/
├── run.php                   # Punkt wejścia
├── collect-planes.php        # CRON co 5 min — próbki samolotów dla PlanesPlugin
├── bootstrap.php             # Wspólny start (autoload, .env, stałe)
├── stories.json              # Plan publikacji: kolejność i włączone slajdy
├── token.php                 # Generowanie nie wygasającego tokenu strony Meta
├── deploy.sh                 # Deploy na hosting
├── .env / .env.example
├── btc_data.txt              # Historia cen BTC (BitcoinPlugin)
├── Inter-Regular.ttf, Inter-Bold.ttf
├── geo/poland-border.php     # Obrys Polski (WeatherPlugin)
├── geo/world.json            # Ląd i granice świata, Natural Earth 1:110m — domena publiczna (WorldMap)
├── geo/europe.json           # Kontury państw Europy, Natural Earth 1:50m, uproszczone (ElectricityPlugin)
├── slides/                   # Slajdy opisane w JSON (TemplateModule)
├── data/                     # Lokalne dane dla źródeł (fakty o płaskiej Ziemi, lista polskich gier)
├── sources/                  # Źródła HTTP opisane w JSON (HttpSource)
├── plugins/                  # Pluginy JSON — wygląd bloków (dane w src/Plugins albo źródle), używane w slajdach węzłem Plugin
├── cache/                    # Ostatnie odpowiedzi źródeł danych (poza repo)
├── .igapi/selection.json     # Zaznaczenie z edytora dla Claude Code (poza repo)
├── admin/                    # Lokalny edytor slajdów (router.php + public/), bez deployu
├── ig-stories/               # Obrazki z trybu debug
├── web/                      # Strona swagnar.dev (statyczna); web/data/ — eksport z run.php (poza repo)
└── src/
    ├── Contracts/   StoryModule, StoryPayload, StoryRunner, NewsPlugin, AbstractPlugin, Bounds
    ├── Data/        DataSource, DataCache, SourceStore, Sources, Sources/* (Asteroids, CuriosityPhoto, DailyFact, Plugin, Http, Broken)
    ├── Helper/      Console, GdImageHelper, Orbit
    ├── Http/        JsonClient, InstagramPublisher
    ├── Layout/      Component, Slide, Stack, VerticalSplit, Text, WrappedText, Stat, Photo,
    │                LineChart, BarChart, ColumnChart, WorldMap, HeatMap, MoonBar, SolarSystem, Gauge, ArcticGlobe, Table, Columns,
    │                SteamList, Separator, Spacer, Custom
    ├── Modules/     PalestineModule, RemembranceModule
    ├── Plugins/     BitcoinPlugin, PopulationPlugin, EarthquakesPlugin, ElectricityPlugin, WeatherPlugin,
    │                PolishGamesPlugin, ClimatePlugin, LaunchPlugin, IssPlugin, AuroraPlugin, BigMacPlugin, SatellitesPlugin,
    │                VoyagerPlugin, PlanetsPlugin, SolarCyclePlugin, FearGreedPlugin, SeaIcePlugin, CurrenciesPlugin,
    │                InflationPlugin, FuelPlugin, PlanesPlugin, MushroomsPlugin, DisasterPlugin
    ├── Template/    TemplateModule, ComponentFactory, JsonPlugins, Playlist, Tracked, SlideJson
    └── Web/         WebExport
```
