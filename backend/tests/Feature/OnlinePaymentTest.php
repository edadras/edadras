<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\Gateways\SandboxGateway;
use App\Payments\Gateways\ZarinpalGateway;
use App\Services\InvoiceService;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Http;
use Tests\ClubTestCase;

/** Paying online: nothing counts until the gateway itself says so. */
class OnlinePaymentTest extends ClubTestCase
{
    protected Member $member;

    protected User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberUser = User::create([
            'tenant_id' => $this->club->id,
            'name' => 'Sara Member',
            'email' => 'sara@test.club',
            'phone' => '09121234567',
            'password' => 'password',
        ]);
        $this->memberUser->roles()->attach(
            Role::where('tenant_id', $this->club->id)->where('slug', 'member')->value('id')
        );

        $this->member = Member::create([
            'user_id' => $this->memberUser->id,
            'code' => '7001',
            'first_name' => 'Sara',
            'last_name' => 'Member',
            'phone' => '09121234567',
        ]);
    }

    protected function asMember(): static
    {
        $this->actingAs($this->memberUser->fresh('roles'), 'sanctum');

        return $this;
    }

    public function test_a_club_without_a_gateway_falls_back_to_the_sandbox(): void
    {
        $this->assertInstanceOf(SandboxGateway::class, app(GatewayManager::class)->default());
        $this->assertFalse(app(GatewayManager::class)->isLive());
    }

    public function test_a_club_connects_its_own_gateway_through_its_settings(): void
    {
        Setting::create(['tenant_id' => $this->club->id, 'key' => 'payments.gateway', 'value' => ['name' => 'zarinpal']]);
        Setting::create(['tenant_id' => $this->club->id, 'key' => 'payments.zarinpal', 'value' => ['merchant_id' => 'abc-123']]);

        $manager = app(GatewayManager::class);
        $manager->flush();

        $this->assertInstanceOf(ZarinpalGateway::class, $manager->default());
        $this->assertTrue($manager->isLive());
    }

    public function test_starting_a_payment_opens_a_pending_row_and_hands_back_a_url(): void
    {
        $response = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 250])
            ->assertCreated();

        $this->assertNotEmpty($response->json('redirect_url'));

        $payment = Payment::find($response->json('payment_id'));

        $this->assertSame('pending', $payment->status);
        $this->assertSame('online', $payment->method);
        $this->assertEquals(250, (float) $payment->amount);

        // Nothing may reach the cash box before the gateway confirms.
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_the_callback_settles_the_payment_and_books_the_income(): void
    {
        app(MembershipService::class)->sell($this->member, $this->plan('duration'));
        $invoice = $this->member->invoices()->firstOrFail();

        $start = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['invoice_id' => $invoice->id])
            ->assertCreated();

        $this->get($start->json('redirect_url'))->assertOk();

        $payment = Payment::find($start->json('payment_id'));

        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('transactions', ['type' => 'income', 'reference_id' => $payment->id]);
    }

    public function test_a_payment_with_no_invoice_lands_in_the_wallet(): void
    {
        $start = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 400])
            ->assertCreated();

        $this->get($start->json('redirect_url'))->assertOk();

        $this->assertEquals(400, (float) $this->member->fresh()->wallet->balance);
    }

    public function test_a_declined_payment_is_marked_failed_and_books_nothing(): void
    {
        $start = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 120])
            ->assertCreated();

        $payment = Payment::find($start->json('payment_id'));
        $token = $payment->gateway_token;

        $this->get("/api/v1/payments/callback/{$this->club->slug}/{$token}?status=NOK")
            ->assertStatus(402);

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_settling_twice_does_not_book_the_income_twice(): void
    {
        $start = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 300])
            ->assertCreated();

        $this->get($start->json('redirect_url'))->assertOk();
        $this->get($start->json('redirect_url'))->assertOk();

        $this->assertEquals(300, (float) $this->member->fresh()->wallet->balance);
    }

    public function test_a_member_cannot_start_a_payment_against_another_member_invoice(): void
    {
        $other = Member::create(['code' => '7002', 'first_name' => 'Other', 'last_name' => 'Member', 'phone' => '09129999999']);
        $invoice = app(InvoiceService::class)->forProducts([], $other->id);

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['invoice_id' => $invoice->id])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invoice_not_this_member');
    }

    public function test_a_zero_amount_is_refused(): void
    {
        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 0])
            ->assertStatus(422);
    }

    public function test_a_callback_for_an_unknown_token_is_a_404(): void
    {
        $this->get("/api/v1/payments/callback/{$this->club->slug}/nope")->assertNotFound();
    }

    public function test_the_gateway_is_asked_to_verify_rather_than_trusted(): void
    {
        Http::fake([
            'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['authority' => 'A0001']], 200),
            'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => '99', 'amount' => 1000]], 200),
        ]);

        Setting::create(['tenant_id' => $this->club->id, 'key' => 'payments.gateway', 'value' => ['name' => 'zarinpal']]);
        Setting::create(['tenant_id' => $this->club->id, 'key' => 'payments.zarinpal', 'value' => ['merchant_id' => 'abc-123']]);
        app(GatewayManager::class)->flush();

        $start = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 100])
            ->assertCreated();

        $payment = Payment::find($start->json('payment_id'));
        $token = $payment->gateway_token;

        $this->get("/api/v1/payments/callback/{$this->club->slug}/{$token}?Status=OK&Authority=A0001")
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        // 1000 Rial verified against a Toman club is 100 Toman.
        $this->assertEquals(100, (float) $payment->fresh()->amount);
        $this->assertSame('99', $payment->fresh()->reference);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'verify.json'));
    }

    public function test_a_gateway_that_says_no_leaves_the_payment_unpaid(): void
    {
        Http::fake([
            'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['authority' => 'A0002']], 200),
            'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => 51]], 200),
        ]);

        Setting::create(['tenant_id' => $this->club->id, 'key' => 'payments.gateway', 'value' => ['name' => 'zarinpal']]);
        Setting::create(['tenant_id' => $this->club->id, 'key' => 'payments.zarinpal', 'value' => ['merchant_id' => 'abc-123']]);
        app(GatewayManager::class)->flush();

        $start = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 100])
            ->assertCreated();

        $payment = Payment::find($start->json('payment_id'));
        $token = $payment->gateway_token;

        $this->get("/api/v1/payments/callback/{$this->club->slug}/{$token}?Status=OK&Authority=A0002")
            ->assertStatus(402);

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_the_member_can_poll_their_own_payment_only(): void
    {
        // Built before the first request, while the tenant context is still set.
        $foreign = Payment::create([
            'member_id' => Member::create(['code' => '7003', 'first_name' => 'X', 'last_name' => 'Y', 'phone' => '09120000003'])->id,
            'amount' => 10,
            'method' => 'online',
            'status' => 'pending',
        ]);

        $start = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/payments/start', ['amount' => 50])
            ->assertCreated();

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/me/payments/{$start->json('payment_id')}/status")
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/me/payments/{$foreign->id}/status")
            ->assertNotFound();
    }
}
