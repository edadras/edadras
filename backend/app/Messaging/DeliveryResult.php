<?php

namespace App\Messaging;

/** What happened to one message on one channel. */
final class DeliveryResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }

    /** Nothing was attempted — no address, or the channel is switched off. */
    public static function skipped(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
