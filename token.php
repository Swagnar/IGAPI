<?php
/**
 * Generuje token strony (Page Access Token) bez daty wygaśnięcia i zapisuje go w .env jako PAGE_ACCESS_TOKEN.
 *
 *   php token.php <krótki_token_użytkownika>
 *
 * Krótki token: Graph API Explorer (developers.facebook.com/tools/explorer) → Twoja aplikacja →
 * „Generate Access Token” z uprawnieniami: instagram_basic, instagram_content_publish,
 * pages_show_list, pages_read_engagement, business_management.
 *
 * Kroki: krótki token użytkownika (~1h) → długoterminowy token użytkownika (60 dni) → token strony,
 * do której podpięte jest konto IG_ID. Token strony wzięty z długoterminowego tokenu nie wygasa
 * (unieważnia go dopiero zmiana hasła, odebranie uprawnień aplikacji itp.).
 *
 * Wymaga w .env: APP_ID, APP_SECRET, IG_ID.
 */
require_once __DIR__ . '/vendor/autoload.php';

const GRAPH = 'https://graph.facebook.com/v25.0/';

$envFile    = __DIR__ . '/.env';
$shortToken = $argv[1] ?? null;

if ($shortToken === null) {
    fwrite(STDERR, "Użycie: php token.php <krótki_token_użytkownika>\n");
    exit(1);
}

$env = Dotenv\Dotenv::parse(file_get_contents($envFile));
foreach (['APP_ID', 'APP_SECRET', 'IG_ID'] as $key) {
    if (empty($env[$key])) {
        fwrite(STDERR, "Brak {$key} w .env (APP_ID i APP_SECRET: developers.facebook.com → aplikacja → Ustawienia → Podstawowe)\n");
        exit(1);
    }
}

function graph(string $path, array $params): array
{
    $ch = curl_init(GRAPH . $path . '?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string)$response, true);
    if (!is_array($data) || isset($data['error'])) {
        fwrite(STDERR, "Błąd Meta ({$path}): " . ($data['error']['message'] ?? $response) . "\n");
        exit(1);
    }
    return $data;
}

// 1. Krótki → długoterminowy token użytkownika
$longUserToken = graph('oauth/access_token', [
    'grant_type'        => 'fb_exchange_token',
    'client_id'         => $env['APP_ID'],
    'client_secret'     => $env['APP_SECRET'],
    'fb_exchange_token' => $shortToken,
])['access_token'];
echo "✓ Długoterminowy token użytkownika uzyskany\n";

// 2. Token strony, do której podpięte jest konto Instagram IG_ID
$pages = graph('me/accounts', [
    'fields'       => 'name,access_token,instagram_business_account',
    'access_token' => $longUserToken,
])['data'] ?? [];

$page = null;
foreach ($pages as $candidate) {
    if (($candidate['instagram_business_account']['id'] ?? null) === $env['IG_ID']) {
        $page = $candidate;
        break;
    }
}
if ($page === null) {
    fwrite(STDERR, "Żadna z dostępnych stron (" . implode(', ', array_column($pages, 'name')) . ") nie ma podpiętego konta IG {$env['IG_ID']}.\n");
    fwrite(STDERR, "Sprawdź, czy przy generowaniu tokenu zaznaczyłeś właściwą stronę.\n");
    exit(1);
}
echo "✓ Token strony „{$page['name']}” uzyskany\n";

// 3. Weryfikacja
$info      = graph('debug_token', [
    'input_token'  => $page['access_token'],
    'access_token' => "{$env['APP_ID']}|{$env['APP_SECRET']}",
])['data'];
$expiresAt = (int)($info['expires_at'] ?? 0);
echo '✓ Wygasa: ' . ($expiresAt === 0 ? 'nigdy' : date('Y-m-d H:i', $expiresAt)) . "\n";

// 4. Zapis do .env (pozostałe wpisy bez zmian)
$contents = file_get_contents($envFile);
$line     = "PAGE_ACCESS_TOKEN='{$page['access_token']}'";
$contents = preg_match('/^PAGE_ACCESS_TOKEN=.*$/m', $contents)
    ? preg_replace('/^PAGE_ACCESS_TOKEN=.*$/m', $line, $contents)
    : rtrim($contents) . "\n{$line}\n";
file_put_contents($envFile, $contents);
echo "✓ Zapisano PAGE_ACCESS_TOKEN w {$envFile}\n";
