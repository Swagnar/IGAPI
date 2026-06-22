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
                'media_type' => 'IMAGE',
                'is_story' => true,
                'access_token' => $this->accessToken,
            ]
        );
        $containerId = $container['data']['id'] ?? throw new RuntimeException("Nie udało się utworzyć kontenera dla obrazu: " . json_encode($container));

        Console::debug("Utworzono kontener: $containerId, mozna publikować");
    }
}