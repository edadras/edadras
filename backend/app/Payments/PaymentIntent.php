<?php

namespace App\Payments;

/** A payment that has been started but not yet paid. */
final class PaymentIntent
{
    public function __construct(
        public readonly string $reference,
        public readonly ?string $redirectUrl = null,
        /** @var array<string, mixed> */
        public readonly array $meta = [],
    ) {}
}
