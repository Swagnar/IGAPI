<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('ROOT_DIR', __DIR__);
define('WIDTH', 1080);
define('HEIGHT', 1920);
define('FONT', __DIR__ . '/Inter-Regular.ttf');
define('FONT_BOLD', __DIR__ . '/Inter-Bold.ttf');


// Ustawienie na true pozwala na testowanie lokalnych plików i wyświetlanie obrazków bezpośrednio z dysku
define('DEBUG', false);

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Http\InstagramPublisher;

use Swagnar\Igapi\Modules\PalestineModule;
use Swagnar\Igapi\Modules\NewsModule;
use Swagnar\Igapi\Modules\RemembranceModule;
use Swagnar\Igapi\Modules\WeatherModule;

use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Contracts\StoryRunner;

const PUBLIC_DIR       = __DIR__ . '/ig-stories';

if(DEBUG) {
    define('PUBLIC_URL', PUBLIC_DIR);
    Console::debug("DEBUG MODE: Pamiętaj aby zmienić przed wdrożeniem na produkcję.");
} else {
    define('PUBLIC_URL', 'https://swagnar.dev/ig-stories');
}

if(!file_exists(FONT)) throw new RuntimeException("Brak fontu: " . FONT);
if(!file_exists(FONT_BOLD)) throw new RuntimeException("Brak fontu: " . FONT_BOLD);

Console::boot("
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
    http: $http
);


// Rejestracja modułów
$modules = [
    new PalestineModule($http),
    new WeatherModule($http),
    // new NewsModule($http),
    // new RemembranceModule($http),
];

Console::boot("Uruchamianie runnera");

try {
    (new StoryRunner($modules, $publisher))->run();
} catch (\Throwable $e) {
    Console::error("Błąd w runnerze: " . $e->getMessage());
    exit(1);
}