<?php

namespace App\Payments;

/** The gateway's verdict on a payment coming back from the bank. */
final class PaymentVerification
{
    private function __construct(
        public readonly bool $paid,
        public readonly ?string $reference = null,
        public readonly ?float $amount = null,
        public readonly ?string $error = null,
    ) {}

    public static function paid(string $reference, ?float $amount = null): self
    {
        return new self(true, $reference, $amount);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
