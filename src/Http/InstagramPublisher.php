<?php

namespace Swagnar\Igapi\Http;

use Swagnar\Igapi\Helper\Console;

use RuntimeException;

class InstagramPublisher
{
    private const BASE_URL = 'https://graph.facebook.com/v25.0/';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $igUserId,
        private readonly JsonClient $http,
    ) {}

    public function publish(string $imageUrl): void {
        $container = $this->http->post(
            self::BASE_URL . "/{$this->igUserId}/media",
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
            self::BASE_URL . "/{$this->igUserId}/media_publish",
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
}