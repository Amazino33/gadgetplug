<?php

namespace App\Services\Messaging;

readonly class DriverSendResult
{
    public function __construct(
        public string $status,
        public ?array $providerResponse = null,
    ) {}

    public static function sent(?array $providerResponse = null): self
    {
        return new self('sent', $providerResponse);
    }

    public static function failed(?array $providerResponse = null): self
    {
        return new self('failed', $providerResponse);
    }

    public static function linkGenerated(?array $providerResponse = null): self
    {
        return new self('link_generated', $providerResponse);
    }
}
