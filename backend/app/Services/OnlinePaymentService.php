<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\Payment;
use App\Payments\GatewayManager;
use App\Payments\PaymentIntent;
use App\Support\Auditor;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Online checkout: open a pending payment, send the member to the gateway,
 * and settle the invoice only once the gateway itself confirms the money
 * moved. The browser's word for it is never enough.
 */
class OnlinePaymentService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly InvoiceService $invoices,
        private readonly WalletService $wallets,
        private readonly TenantContext $tenancy,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Starts a payment against an invoice, or a top-up with no invoice behind
     * it. Returns the pending row plus where to send the payer.
     *
     * @return array{payment: Payment, intent: PaymentIntent}
     */
    public function start(Member $member, float $amount, ?Invoice $invoice = null, ?string $returnUrl = null): array
    {
        if ($invoice && $invoice->member_id !== $member->id) {
            throw new RuntimeException('invoice_not_this_member');
        }

        if ($amount <= 0) {
            throw new RuntimeException('amount_must_be_positive');
        }

        $gateway = $this->gateways->default();

        $payment = Payment::create([
            'invoice_id' => $invoice?->id,
            'member_id' => $member->id,
            'amount' => $amount,
            'method' => 'online',
            'gateway' => $gateway->name(),
            // A handle of our own, so the callback finds this row without
            // trusting anything the gateway echoes back — and keeps finding it
            // after the gateway's own reference has been written.
            'gateway_token' => Str::lower(Str::random(40)),
            'status' => 'pending',
        ]);

        try {
            $intent = $gateway->start($payment, $this->callbackUrl($payment, $returnUrl));
        } catch (RuntimeException $e) {
            $payment->update(['status' => 'failed']);

            throw $e;
        }

        $payment->update(['reference' => $intent->reference]);

        return ['payment' => $payment->fresh(), 'intent' => $intent];
    }

    /**
     * Settles a payment coming back from the gateway. Safe to call twice —
     * payers refresh the callback page, and gateways retry their webhooks.
     *
     * @param  array<string, mixed>  $callback
     */
    public function settle(Payment $payment, array $callback): Payment
    {
        if ($payment->status === 'paid') {
            return $payment;
        }

        $verification = $this->gateways->gateway($payment->gateway ?? 'sandbox')->verify($payment, $callback);

        if (! $verification->paid) {
            $payment->update(['status' => 'failed']);
            $this->auditor->log('payment.failed', $payment, [], ['reason' => $verification->error]);

            return $payment->fresh();
        }

        // The gateway is the authority on the amount; if it took less than we
        // asked for, that lower figure is what the club actually received.
        $amount = $verification->amount ?: (float) $payment->amount;

        return DB::transaction(function () use ($payment, $verification, $amount) {
            $payment->update([
                'status' => 'paid',
                'amount' => $amount,
                'reference' => $verification->reference,
                'paid_at' => now(),
            ]);

            if ($payment->invoice) {
                $payment->invoice->refresh()->recalculate()->save();
                $this->invoices->recordIncomeFor($payment);
                $this->invoices->settleMembershipPayments($payment->invoice);
            } else {
                // Nothing to settle against, so it lands in the member's wallet.
                $this->wallets->credit($payment->member, $amount, __('general.online_topup'), $payment);
            }

            $this->auditor->log('payment.settled', $payment, [], [
                'gateway' => $payment->gateway,
                'amount' => $amount,
            ]);

            return $payment->fresh('invoice');
        });
    }

    /** Finds the row a callback belongs to, by the handle we issued. */
    public function findByToken(string $token): ?Payment
    {
        return Payment::where('gateway_token', $token)->first();
    }

    protected function callbackUrl(Payment $payment, ?string $returnUrl): string
    {
        return route('payments.callback', [
            'tenant' => $this->tenancy->get()?->slug,
            'token' => $payment->gateway_token,
        ]).($returnUrl ? '&return='.urlencode($returnUrl) : '');
    }
}
