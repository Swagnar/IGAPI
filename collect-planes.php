<?php
/**
 * Próbka samolotów nad Polską do cache/planes/<data>.jsonl — CRON co 5 minut. Z tych próbek PlanesPlugin
 * składa o 6:00 wszystkie loty z poprzedniej doby (live API adsb.fi nie ma historii).
 *
 *   php collect-planes.php
 */
require_once __DIR__ . '/bootstrap.php';

use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Plugins\PlanesPlugin;

try {
    $count = (new PlanesPlugin(new JsonClient()))->collect();
    Console::success("collect-planes: {$count} samolotów w próbce");
} catch (Throwable $e) {
    Console::error('collect-planes: ' . $e->getMessage());
    exit(1);
}
