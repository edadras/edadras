<?php

namespace App\Services;

use App\Messaging\ChannelManager;
use App\Messaging\Recipient;
use App\Models\Campaign;
use App\Models\Member;
use App\Support\Auditor;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a campaign into actual messages. It resolves the audience, sends one
 * message per member on the campaign's channel, and records how many landed.
 *
 * The body supports {name}, {code}, {expires}, {sessions} and {club}, so one
 * campaign reads as if it were written for each member.
 */
class CampaignDispatcher
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly TenantContext $tenancy,
        private readonly Auditor $auditor,
    ) {}

    /** @return array{recipients:int, delivered:int, failed:int, skipped:int} */
    public function send(Campaign $campaign): array
    {
        $campaign->update(['status' => 'sending']);

        $channel = $this->channels->channel($campaign->channel);
        $context = $this->context($campaign);
        $recipients = $this->audience($campaign->audience ?? ['type' => 'all']);

        $delivered = $failed = $skipped = 0;

        foreach ($recipients as $member) {
            $recipient = Recipient::fromMember($member);
            $result = $channel->send($recipient, $this->personalise($campaign->body, $member), $context);

            match (true) {
                $result->delivered => $delivered++,
                $result->error !== null && str_contains($result->error, 'no_') => $skipped++,
                default => $failed++,
            };
        }

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients_count' => $recipients->count(),
            'delivered_count' => $delivered,
        ]);

        $this->auditor->log('campaign.sent', $campaign, [], [
            'channel' => $campaign->channel,
            'recipients' => $recipients->count(),
            'delivered' => $delivered,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);

        return [
            'recipients' => $recipients->count(),
            'delivered' => $delivered,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /** @return Collection<int, Member> */
    public function audience(array $audience): Collection
    {
        $days = (int) ($audience['days'] ?? 30);

        return match ($audience['type'] ?? 'all') {
            'active' => Member::active()->with('user.pushTokens', 'activeMembership')->get(),
            'inactive' => Member::active()
                ->whereDoesntHave('attendances', fn ($q) => $q->where('checked_in_at', '>=', now()->subDays($days)))
                ->with('user.pushTokens', 'activeMembership')
                ->get(),
            'expiring' => Member::active()
                ->whereHas('memberships', fn ($q) => $q->expiringWithin($days))
                ->with('user.pushTokens', 'activeMembership')
                ->get(),
            'birthday' => Member::active()
                ->whereNotNull('birth_date')
                ->whereMonth('birth_date', today()->month)
                ->with('user.pushTokens', 'activeMembership')
                ->get(),
            'selected' => Member::whereIn('id', $audience['member_ids'] ?? [])
                ->with('user.pushTokens', 'activeMembership')
                ->get(),
            default => Member::with('user.pushTokens', 'activeMembership')->get(),
        };
    }

    /** Fills the placeholders a club can put in a campaign body. */
    public function personalise(string $body, Member $member): string
    {
        $membership = $member->activeMembership;

        return Str::swap([
            '{name}' => $member->first_name,
            '{full_name}' => $member->full_name,
            '{code}' => $member->code,
            '{club}' => $this->tenancy->get()?->name ?? '',
            '{expires}' => $membership?->ends_at?->toDateString() ?? '—',
            '{sessions}' => (string) ($membership?->remaining_sessions ?? '—'),
        ], $body);
    }

    /** @return array<string, mixed> */
    protected function context(Campaign $campaign): array
    {
        $club = $this->tenancy->get();

        return [
            'title' => $campaign->title,
            'subject' => $campaign->title,
            'club_name' => $club?->name,
            'brand_color' => $club?->brand_color ?? config('gymflow.brand.primary'),
            'logo_url' => $club?->logo_path ? asset('storage/'.$club->logo_path) : null,
            'data' => ['campaign_id' => $campaign->id, 'type' => 'campaign'],
        ];
    }
}
