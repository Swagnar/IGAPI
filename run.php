<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('WIDTH', 1080);
define('HEIGHT', 1920);
define('HEADER_SIZE', 72);
define('OFFSET_Y', 150);

define('FONT', __DIR__ . '/Inter-Regular.ttf');
define('FONT_BOLD', __DIR__ . '/Inter-Bold.ttf');

// Produkcja wymaga jawnego DEBUG=false w .env — brak wpisu = tryb debug, żeby lokalny run niczego nie opublikował
define('DEBUG', filter_var($_ENV['DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN));

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Http\InstagramPublisher;

use Swagnar\Igapi\Modules\PalestineModule;
use Swagnar\Igapi\Modules\NewsModule;
use Swagnar\Igapi\Modules\RemembranceModule;
use Swagnar\Igapi\Modules\WeatherModule;
use Swagnar\Igapi\Modules\KosmosModule;

use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Contracts\StoryRunner;

define('ROOT_DIR', __DIR__);

if (DEBUG) {
    define('PUBLIC_DIR', __DIR__ . '/ig-stories');
    define('PUBLIC_URL', __DIR__ . '/ig-stories');
    Console::debug("DEBUG MODE: Pamiętaj aby zmienić przed wdrożeniem na produkcję.");
} else {
    // Katalog musi leżeć w document root domeny z PUBLIC_URL — Instagram pobiera obrazki po URL-u
    define('PUBLIC_DIR', $_ENV['PUBLIC_DIR'] ?? ROOT_DIR . '/../swagnar.dev/ig-stories');
    define('PUBLIC_URL', $_ENV['PUBLIC_URL'] ?? 'https://swagnar.dev/ig-stories');
}

if(!file_exists(FONT)) throw new RuntimeException("Brak fontu: " . FONT);
if(!file_exists(FONT_BOLD)) throw new RuntimeException("Brak fontu: " . FONT_BOLD);
if(!is_dir(PUBLIC_DIR) || !is_writable(PUBLIC_DIR)) throw new RuntimeException("Brak katalogu lub brak praw zapisu: " . PUBLIC_DIR);

Console::write('', Console::WHITE,"
  ██╗ ██████╗      █████╗ ██████╗ ██╗
  ██║██╔════╝     ██╔══██╗██╔══██╗██║
  ██║██║  ███╗    ███████║██████╔╝██║
  ██║██║   ██║    ██╔══██║██╔═══╝ ██║
  ██║╚██████╔╝    ██║  ██║██║     ██║
  ╚═╝ ╚═════╝     ╚═╝  ╚═╝╚═╝     ╚═╝

  Instagram Story Publisher
  ─────────────────────────────────
");
Console::boot("Inicjalizacja helperów");

$http = new JsonClient();
$publisher = new InstagramPublisher(
    accessToken: $_ENV['PAGE_ACCESS_TOKEN'],
    igUserId: $_ENV['IG_ID'],
    http: $http,
    appId: $_ENV['APP_ID'] ?? null,
    appSecret: $_ENV['APP_SECRET'] ?? null,
);


// Rejestracja modułów
$modules = [
    new PalestineModule($http),
    new NewsModule($http),
    new KosmosModule($http),
    new WeatherModule($http),
    new RemembranceModule($http),
];

Console::boot("Uruchamianie runnera");

try {
    (new StoryRunner($modules, $publisher))->run();
} catch (\Throwable $e) {
    Console::error("Błąd w runnerze: " . $e->getMessage());
}