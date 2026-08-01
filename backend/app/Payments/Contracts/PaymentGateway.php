<?php

namespace App\Payments\Contracts;

use App\Models\Payment;
use App\Payments\PaymentIntent;
use App\Payments\PaymentVerification;

interface PaymentGateway
{
    /** The short name a payment row records, e.g. "zarinpal". */
    public function name(): string;

    /** Opens a payment at the gateway and returns where to send the payer. */
    public function start(Payment $payment, string $callbackUrl): PaymentIntent;

    /**
     * Confirms with the gateway that the money actually moved. Never trust the
     * browser's word for it — this call is what decides.
     *
     * @param  array<string, mixed>  $callback
     */
    public function verify(Payment $payment, array $callback): PaymentVerification;

    public function isConfigured(): bool;
}
