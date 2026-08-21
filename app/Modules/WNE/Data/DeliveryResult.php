<?php

namespace App\Modules\WNE\Data;

class DeliveryResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
