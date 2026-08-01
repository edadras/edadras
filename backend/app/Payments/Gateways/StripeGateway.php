<?php

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\PaymentIntent;
use App\Payments\PaymentVerification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Stripe Checkout, for clubs billing outside Iran. The hosted page does the
 * card handling, so no card details ever reach this application.
 */
class StripeGateway implements PaymentGateway
{
    /** Currencies Stripe quotes in whole units rather than hundredths. */
    protected const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function start(Payment $payment, string $callbackUrl): PaymentIntent
    {
        $currency = strtolower(Arr::get($this->config, 'currency', 'usd'));

        $response = Http::withToken($this->config['secret'])
            ->asForm()
            ->timeout(20)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $callbackUrl.(str_contains($callbackUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}&status=OK',
                'cancel_url' => $callbackUrl.(str_contains($callbackUrl, '?') ? '&' : '?').'status=CANCELLED',
                'client_reference_id' => (string) $payment->id,
                'customer_email' => $payment->member?->email,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $this->toMinorUnits((float) $payment->amount, $currency),
                        'product_data' => ['name' => Arr::get($this->config, 'description', config('app.name'))],
                    ],
                ]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('stripe_session_failed: '.Arr::get($response->json(), 'error.message', $response->status()));
        }

        return new PaymentIntent(
            reference: $response->json('id'),
            redirectUrl: $response->json('url'),
        );
    }

    public function verify(Payment $payment, array $callback): PaymentVerification
    {
        $sessionId = $callback['session_id'] ?? $payment->reference;

        if (($callback['status'] ?? null) === 'CANCELLED' || blank($sessionId)) {
            return PaymentVerification::failed('stripe_cancelled');
        }

        try {
            $response = Http::withToken($this->config['secret'])
                ->timeout(20)
                ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

            if ($response->failed()) {
                return PaymentVerification::failed('stripe_'.$response->status());
            }

            if ($response->json('payment_status') !== 'paid') {
                return PaymentVerification::failed('stripe_unpaid');
            }

            $currency = strtolower($response->json('currency') ?? 'usd');

            return PaymentVerification::paid(
                (string) ($response->json('payment_intent') ?? $sessionId),
                $this->fromMinorUnits((int) $response->json('amount_total'), $currency),
            );
        } catch (Throwable $e) {
            return PaymentVerification::failed('stripe_error: '.$e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return filled(Arr::get($this->config, 'secret'));
    }

    protected function toMinorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * (in_array($currency, self::ZERO_DECIMAL, true) ? 1 : 100));
    }

    protected function fromMinorUnits(int $amount, string $currency): float
    {
        return $amount / (in_array($currency, self::ZERO_DECIMAL, true) ? 1 : 100);
    }
}
