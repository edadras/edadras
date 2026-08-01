<?php

namespace Tests\Feature;

use App\Jobs\SendCampaign;
use App\Mail\ClubMessage;
use App\Messaging\ChannelManager;
use App\Messaging\Channels\LogChannel;
use App\Messaging\Channels\SmsChannel;
use App\Models\Campaign;
use App\Models\Member;
use App\Models\Setting;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\ClubTestCase;

/** Campaigns have to actually leave the building. */
class CampaignDeliveryTest extends ClubTestCase
{
    protected function campaign(string $channel = 'sms', array $audience = ['type' => 'all'], string $body = 'Hello {name}'): Campaign
    {
        return Campaign::create([
            'title' => 'Renewal push',
            'channel' => $channel,
            'body' => $body,
            'audience' => $audience,
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);
    }

    protected function member(string $code = '5001', array $attributes = []): Member
    {
        return Member::create(array_merge([
            'code' => $code,
            'first_name' => 'Ali',
            'last_name' => 'Ahmadi',
            'phone' => '0912111'.$code,
            'email' => "member{$code}@test.club",
        ], $attributes));
    }

    public function test_an_unconfigured_channel_falls_back_to_the_log_driver(): void
    {
        $this->assertInstanceOf(LogChannel::class, app(ChannelManager::class)->channel('sms'));
        $this->assertFalse(app(ChannelManager::class)->isLive('sms'));
    }

    public function test_a_club_brings_its_own_sms_gateway_through_its_settings(): void
    {
        Setting::create([
            'tenant_id' => $this->club->id,
            'key' => 'messaging.sms',
            'value' => ['url' => 'https://sms.example.test/send', 'token' => 'secret', 'sender' => '3000'],
        ]);

        $manager = app(ChannelManager::class);
        $manager->flush();

        $this->assertInstanceOf(SmsChannel::class, $manager->channel('sms'));
        $this->assertTrue($manager->isLive('sms'));
    }

    public function test_sending_a_campaign_calls_the_gateway_once_per_member(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['messageId' => 'abc'], 200)]);

        Setting::create([
            'tenant_id' => $this->club->id,
            'key' => 'messaging.sms',
            'value' => ['url' => 'https://sms.example.test/send', 'token' => 'secret'],
        ]);
        app(ChannelManager::class)->flush();

        $this->member('5001');
        $this->member('5002');

        $campaign = $this->campaign();

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$campaign->id}/send")
            ->assertOk();

        $this->assertSame(2, $response->json('recipients'));
        $this->assertSame(2, $response->json('delivered'));
        $this->assertSame('sent', $campaign->fresh()->status);

        Http::assertSentCount(2);
    }

    public function test_the_body_is_personalised_for_each_member(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['messageId' => 'abc'], 200)]);

        Setting::create([
            'tenant_id' => $this->club->id,
            'key' => 'messaging.sms',
            'value' => ['url' => 'https://sms.example.test/send'],
        ]);
        app(ChannelManager::class)->flush();

        $member = $this->member('5003', ['first_name' => 'Sara']);
        app(MembershipService::class)->sell($member, $this->plan('session'), ['create_invoice' => false]);

        $campaign = $this->campaign(body: 'Hi {name}, you have {sessions} sessions left at {club}.');

        $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$campaign->id}/send")
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request['text'], 'Hi Sara')
                && str_contains($request['text'], 'Test Club')
                && ! str_contains($request['text'], '{sessions}');
        });
    }

    public function test_a_member_the_channel_cannot_reach_is_skipped_not_failed(): void
    {
        Mail::fake();

        $this->member('5004');
        Member::create(['code' => '5005', 'first_name' => 'No', 'last_name' => 'Email', 'phone' => '09120005005']);

        $response = $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$this->campaign('email')->id}/send")
            ->assertOk();

        $this->assertSame(2, $response->json('recipients'));
        $this->assertSame(1, $response->json('delivered'));
        $this->assertSame(1, $response->json('skipped'));

        Mail::assertSentCount(1);
    }

    public function test_a_gateway_failure_is_counted_not_swallowed(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['error' => 'nope'], 500)]);

        Setting::create([
            'tenant_id' => $this->club->id,
            'key' => 'messaging.sms',
            'value' => ['url' => 'https://sms.example.test/send'],
        ]);
        app(ChannelManager::class)->flush();

        $this->member('5006');

        $response = $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$this->campaign()->id}/send")
            ->assertOk();

        $this->assertSame(0, $response->json('delivered'));
        $this->assertSame(1, $response->json('failed'));
    }

    public function test_an_email_campaign_sends_a_club_branded_mailable(): void
    {
        Mail::fake();

        $this->member('5007');

        $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$this->campaign('email')->id}/send")
            ->assertOk();

        Mail::assertSent(ClubMessage::class, fn (ClubMessage $mail) => $mail->clubName === 'Test Club');
    }

    public function test_a_large_audience_goes_to_the_queue(): void
    {
        Queue::fake();

        for ($i = 0; $i < 30; $i++) {
            $this->member('60'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $campaign = $this->campaign();

        $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$campaign->id}/send")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        Queue::assertPushed(SendCampaign::class, fn (SendCampaign $job) => $job->campaignId === $campaign->id
            && $job->tenantId === $this->club->id);

        $this->assertSame('sending', $campaign->fresh()->status);
    }

    public function test_a_campaign_cannot_be_sent_twice(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['status' => 'sent']);

        $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$campaign->id}/send")
            ->assertStatus(422);
    }

    public function test_the_preview_shows_what_one_member_would_receive(): void
    {
        $member = $this->member('5008', ['first_name' => 'Reza']);

        $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/campaigns/preview', [
                'body' => 'Hello {name} of {club}',
                'member_id' => $member->id,
            ])
            ->assertOk()
            ->assertJsonPath('body', 'Hello Reza of Test Club');
    }

    public function test_the_channel_status_tells_the_panel_what_is_live(): void
    {
        $this->asUser()->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/campaigns/channels')
            ->assertOk()
            ->assertJsonPath('channels.0.channel', 'sms')
            ->assertJsonPath('channels.0.live', false)
            ->assertJsonStructure(['placeholders']);
    }

    public function test_the_send_is_recorded_in_the_audit_trail(): void
    {
        $this->member('5009');

        $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/campaigns/{$this->campaign('email')->id}/send")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.sent', 'tenant_id' => $this->club->id]);
    }
}
