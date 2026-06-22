<?php
namespace Swagnar\Igapi\Contracts;

final class StoryPayload
{
    public function __construct(
        public readonly array $slides,
        public readonly string $label,
    ) {}

    public static function single(string $path, string $url, string $label): self {
        return new self(
            [
                [
                    'path' => $path,
                    'url'  => $url,
                ]
            ],
            $label
        );
    }
}