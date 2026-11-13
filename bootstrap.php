<?php
// Wspólny start dla run.php i lokalnego edytora (admin/): autoload, .env i stałe layoutu
require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->load();

define('ROOT_DIR', __DIR__);

define('WIDTH', 1080);
define('HEIGHT', 1920);
define('HEADER_SIZE', 72);
define('OFFSET_Y', 150);

define('FONT', __DIR__ . '/Inter-Regular.ttf');
define('FONT_BOLD', __DIR__ . '/Inter-Bold.ttf');
