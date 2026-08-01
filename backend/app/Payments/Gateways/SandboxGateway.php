<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\PaymentIntent;
use App\Payments\PaymentVerification;
use Illuminate\Support\Str;

/**
 * The default when a club has not connected a real gateway. It hands back a
 * callback URL that approves the payment, so the whole flow — start, redirect,
 * verify, receipt — can be walked through end to end before any bank is
 * involved. It never moves money.
 */
class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function start(Payment $payment, string $callbackUrl): PaymentIntent
    {
        $reference = 'sbx_'.Str::lower(Str::random(24));

        return new PaymentIntent(
            reference: $reference,
            redirectUrl: $callbackUrl.(str_contains($callbackUrl, '?') ? '&' : '?').http_build_query([
                'status' => 'OK',
                'reference' => $reference,
            ]),
            meta: ['sandbox' => true],
        );
    }

    public function verify(Payment $payment, array $callback): PaymentVerification
    {
        return ($callback['status'] ?? null) === 'OK'
            ? PaymentVerification::paid($callback['reference'] ?? $payment->reference ?? 'sandbox', (float) $payment->amount)
            : PaymentVerification::failed('sandbox_declined');
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
