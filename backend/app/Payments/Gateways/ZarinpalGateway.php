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
 * Zarinpal, the gateway most Iranian clubs already have. Amounts are sent in
 * Rial, so a club whose currency is Toman has its figure multiplied by ten on
 * the way out and the verified amount divided by ten on the way back.
 */
class ZarinpalGateway implements PaymentGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'zarinpal';
    }

    public function start(Payment $payment, string $callbackUrl): PaymentIntent
    {
        $response = Http::timeout(20)->post($this->base().'/pg/v4/payment/request.json', [
            'merchant_id' => $this->config['merchant_id'],
            'amount' => $this->toRial((float) $payment->amount),
            'callback_url' => $callbackUrl,
            'description' => Arr::get($this->config, 'description', config('app.name')),
            'metadata' => array_filter([
                'mobile' => $payment->member?->phone,
                'email' => $payment->member?->email,
            ]),
        ]);

        $authority = Arr::get($response->json(), 'data.authority');

        if ($response->failed() || blank($authority)) {
            throw new RuntimeException('zarinpal_request_failed: '.Arr::get($response->json(), 'errors.message', $response->status()));
        }

        return new PaymentIntent(
            reference: $authority,
            redirectUrl: $this->base().'/pg/StartPay/'.$authority,
        );
    }

    public function verify(Payment $payment, array $callback): PaymentVerification
    {
        if (($callback['Status'] ?? $callback['status'] ?? null) !== 'OK') {
            return PaymentVerification::failed('zarinpal_cancelled');
        }

        try {
            $response = Http::timeout(20)->post($this->base().'/pg/v4/payment/verify.json', [
                'merchant_id' => $this->config['merchant_id'],
                'amount' => $this->toRial((float) $payment->amount),
                'authority' => $callback['Authority'] ?? $callback['authority'] ?? $payment->reference,
            ]);

            $code = (int) Arr::get($response->json(), 'data.code', 0);

            // 100 is a fresh success; 101 means it was already verified, which
            // happens when the payer refreshes the callback page.
            if (! in_array($code, [100, 101], true)) {
                return PaymentVerification::failed('zarinpal_code_'.$code);
            }

            return PaymentVerification::paid(
                (string) Arr::get($response->json(), 'data.ref_id'),
                $this->fromRial((float) Arr::get($response->json(), 'data.amount', 0)),
            );
        } catch (Throwable $e) {
            return PaymentVerification::failed('zarinpal_error: '.$e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return filled(Arr::get($this->config, 'merchant_id'));
    }

    protected function base(): string
    {
        return Arr::get($this->config, 'sandbox', false)
            ? 'https://sandbox.zarinpal.com'
            : 'https://payment.zarinpal.com';
    }

    protected function toRial(float $amount): int
    {
        return (int) round($amount * (Arr::get($this->config, 'currency', 'IRT') === 'IRT' ? 10 : 1));
    }

    protected function fromRial(float $amount): float
    {
        return $amount / (Arr::get($this->config, 'currency', 'IRT') === 'IRT' ? 10 : 1);
    }
}
