<?php

namespace Swagnar\Igapi\Http;

use Swagnar\Igapi\Helper\Console;

use RuntimeException;

class InstagramPublisher
{
    private const BASE_URL = 'https://graph.facebook.com/v25.0/';

    private const EXPIRY_WARNING_DAYS = 14;

    public function __construct(
        private readonly string $accessToken,
        private readonly string $igUserId,
        private readonly JsonClient $http,
        private readonly ?string $appId = null,
        private readonly ?string $appSecret = null,
    ) {}

    public function publish(string $imageUrl): void {
        $container = $this->http->post(
            self::BASE_URL . "{$this->igUserId}/media",
            [
                'image_url' => $imageUrl,
                'media_type' => 'STORIES',
                'is_story' => true,
                'access_token' => $this->accessToken,
            ]
        );
        $containerId = $container['data']['id'] ?? throw new RuntimeException("Nie udało się utworzyć kontenera dla obrazu: " . json_encode($container));

        Console::debug("Utworzono kontener: {$containerId}, czekam na gotowość...");

        if (!$this->waitUntilContainerReady($containerId)) {
            Console::error("Rezygnuję z publikacji — kontener nie jest gotowy.");
            return;
        }

        Console::debug("Kontener gotowy, próbuję publikować...");
        $result = $this->graph_post(
            self::BASE_URL . "{$this->igUserId}/media_publish",
            [
                'creation_id' => $containerId,
                'access_token' => $this->accessToken,
            ]
        );

        Console::success("Opublikowano!\n" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private function graph_post(string $url, array $params): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException(curl_error($ch));
        }
        curl_close($ch);
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException("Nieprawidłowa odpowiedź Meta: $response");
        }
        if (isset($data['error'])) {
            throw new RuntimeException("Meta error: " . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
        }
        return $data;
    }
    private function waitUntilContainerReady(string $containerId, int $maxAttempts = 10, int $delaySeconds = 3): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->http->get(self::BASE_URL . "{$containerId}?fields=status_code&access_token={$this->accessToken}");
            $status = $response['status_code'] ?? null;   // bez ['data']

            Console::debug("Status kontenera {$containerId}: {$status} (próba " . ($i + 1) . "/{$maxAttempts})");

            if ($status === 'FINISHED') {
                return true;
            }

            if ($status === 'ERROR') {
                Console::error("Kontener {$containerId} zakończył się błędem podczas przetwarzania.");
                return false;
            }

            sleep($delaySeconds);
        }

        Console::error("Kontener {$containerId} nie osiągnął statusu FINISHED po {$maxAttempts} próbach.");
        return false;
    }

    /**
     * Sprawdza token przed publikacją. Z APP_ID/APP_SECRET używa debug_token, który zwraca też datę wygaśnięcia
     * (0 = token nie wygasa — tak jest dla tokenu strony wygenerowanego przez token.php).
     * Bez nich — tylko proste zapytanie /me (ważny / nieważny).
     *
     * Wygasłego tokenu nie da się odnowić przez API — trzeba wygenerować nowy: php token.php <krótki_token>
     *
     * @return array{valid: bool, message: ?string, expiresAt: ?int}
     */
    public function checkTokenStatus(): array
    {
        if ($this->appId && $this->appSecret) {
            return $this->debugToken();
        }

        $response = $this->graphGet("me?fields=id&access_token={$this->accessToken}");
        if (isset($response['id'])) {
            return ['valid' => true, 'message' => null, 'expiresAt' => null];
        }

        return ['valid' => false, 'message' => $response['error']['message'] ?? 'Nieznany błąd walidacji tokenu', 'expiresAt' => null];
    }

    private function debugToken(): array
    {
        $response = $this->graphGet('debug_token?' . http_build_query([
            'input_token'  => $this->accessToken,
            'access_token' => "{$this->appId}|{$this->appSecret}",
        ]));
        $data = $response['data'] ?? null;

        if ($data === null) {
            return ['valid' => false, 'message' => $response['error']['message'] ?? 'Nieznany błąd debug_token', 'expiresAt' => null];
        }
        if (!($data['is_valid'] ?? false)) {
            return ['valid' => false, 'message' => $data['error']['message'] ?? 'Token jest nieważny', 'expiresAt' => $data['expires_at'] ?? null];
        }

        $expiresAt = (int)($data['expires_at'] ?? 0);
        $type      = $data['type'] ?? '?';

        if ($expiresAt === 0) {
            Console::info("Token Meta ({$type}) nie ma daty wygaśnięcia.");
        } else {
            $daysLeft = (int) floor(($expiresAt - time()) / 86400);
            $message  = "Token Meta ({$type}) wygasa " . date('Y-m-d H:i', $expiresAt) . " (za {$daysLeft} dni).";
            $daysLeft <= self::EXPIRY_WARNING_DAYS
                ? Console::warn($message . ' Wygeneruj token strony bez daty wygaśnięcia: php token.php <krótki_token>')
                : Console::info($message);
        }

        return ['valid' => true, 'message' => null, 'expiresAt' => $expiresAt];
    }

    private function graphGet(string $path): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['error' => ['message' => "Błąd połączenia: {$curlErr}"]];
        }

        return json_decode($response, true) ?? ['error' => ['message' => "Nieprawidłowa odpowiedź Meta: {$response}"]];
    }
}
