<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Payments\GatewayManager;
use App\Services\OnlinePaymentService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Starting an online payment, and handling what the gateway sends back. */
class OnlinePaymentController extends Controller
{
    public function __construct(
        private readonly OnlinePaymentService $payments,
        private readonly GatewayManager $gateways,
        private readonly TenantContext $tenancy,
    ) {}

    /** Which gateway this club pays through, for the app to show. */
    public function gateway(): JsonResponse
    {
        return response()->json([
            'gateway' => $this->gateways->defaultName(),
            'live' => $this->gateways->isLive(),
            'currency' => $this->tenancy->get()?->currency,
        ]);
    }

    /**
     * The member taps "pay" and gets a URL to open. Nothing is settled here —
     * the money is only recognised when the gateway confirms it.
     */
    public function start(Request $request): JsonResponse
    {
        $member = $request->user()->member;

        abort_unless($member, 403, __('auth.not_a_member'));

        $data = $request->validate([
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'amount' => ['required_without:invoice_id', 'nullable', 'numeric', 'min:1'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = isset($data['invoice_id']) ? Invoice::findOrFail($data['invoice_id']) : null;
        $amount = (float) ($data['amount'] ?? ($invoice ? $invoice->total - $invoice->paid_amount : 0));

        try {
            ['payment' => $payment, 'intent' => $intent] = $this->payments->start(
                $member,
                $amount,
                $invoice,
                $data['return_url'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => __('payments.'.$e->getMessage()), 'reason' => $e->getMessage()], 422);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'amount' => (float) $payment->amount,
            'gateway' => $payment->gateway,
            'redirect_url' => $intent->redirectUrl,
        ], 201);
    }

    /**
     * Where the gateway sends the payer back. It carries no session, so the
     * club comes from the URL and the payment from our own token — never from
     * anything the gateway echoes.
     */
    public function callback(Request $request, string $tenant, string $token): RedirectResponse|JsonResponse
    {
        $club = Tenant::where('slug', $tenant)->first();

        abort_unless($club, 404);

        return $this->tenancy->run($club, function () use ($request, $token) {
            $payment = $this->payments->findByToken($token);

            abort_unless($payment, 404);

            $settled = $this->payments->settle($payment, $request->all());

            if ($return = $request->query('return')) {
                return redirect()->away($return.(str_contains($return, '?') ? '&' : '?').http_build_query([
                    'payment' => $settled->status,
                    'amount' => (float) $settled->amount,
                ]));
            }

            return response()->json([
                'status' => $settled->status,
                'amount' => (float) $settled->amount,
                'reference' => $settled->reference,
                'message' => __($settled->status === 'paid' ? 'payments.paid' : 'payments.failed'),
            ], $settled->status === 'paid' ? 200 : 402);
        });
    }

    /** The member app polls this after coming back from the browser. */
    public function status(Request $request, int $payment): JsonResponse
    {
        $member = $request->user()->member;

        abort_unless($member, 403, __('auth.not_a_member'));

        $row = $member->payments()->whereKey($payment)->firstOrFail();

        return response()->json([
            'status' => $row->status,
            'amount' => (float) $row->amount,
            'paid_at' => $row->paid_at?->toDateTimeString(),
        ]);
    }
}
