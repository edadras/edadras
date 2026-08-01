<?php

namespace App\Reports;

use App\Models\AiInsight;
use App\Models\Campaign;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Member;
use App\Models\PushToken;
use App\Reports\Concerns\AggregatesRows;

/** Campaigns, chat and the AI module: how the club talks to its members. */
class EngagementReports
{
    use AggregatesRows;

    public function campaigns($from, $to): array
    {
        return Campaign::whereBetween('created_at', [$from, $to])
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Campaign $c) => [
                'title' => $c->title,
                'channel' => $c->channel,
                'status' => $c->status,
                'audience' => $c->audience['type'] ?? null,
                'recipients' => $c->recipients_count,
                'delivered' => $c->delivered_count,
                'delivery_rate' => $c->recipients_count ? round($c->delivered_count / $c->recipients_count * 100, 1) : 0.0,
                'sent_at' => $c->sent_at?->toDateTimeString(),
                'by' => $c->creator?->name,
            ])
            ->all();
    }

    public function campaignsByChannel($from, $to): array
    {
        return $this->breakdown(Campaign::whereBetween('created_at', [$from, $to]), 'channel');
    }

    public function campaignsByStatus($from, $to): array
    {
        return $this->breakdown(Campaign::whereBetween('created_at', [$from, $to]), 'status');
    }

    public function campaignReach($from, $to): array
    {
        $sent = Campaign::where('status', 'sent')->whereBetween('sent_at', [$from, $to])->get();

        return [
            'campaigns' => $sent->count(),
            'recipients' => (int) $sent->sum('recipients_count'),
            'delivered' => (int) $sent->sum('delivered_count'),
            'active_members' => Member::active()->count(),
            'by_channel' => $sent
                ->groupBy('channel')
                ->map(fn ($rows, $channel) => [
                    'label' => (string) $channel,
                    'campaigns' => $rows->count(),
                    'delivered' => (int) $rows->sum('delivered_count'),
                ])
                ->values()
                ->all(),
        ];
    }

    public function chatVolume(int $days): array
    {
        return $this->dailySeries(ChatMessage::query(), 'created_at', $days);
    }

    public function chatBySender($from, $to): array
    {
        return $this->breakdown(ChatMessage::whereBetween('created_at', [$from, $to]), 'sender_type');
    }

    /** A member wrote, nobody wrote back. The clearest service failure there is. */
    public function unansweredThreads(): array
    {
        return Conversation::with(['member:id,code,first_name,last_name', 'coach:id,first_name,last_name'])
            ->get()
            ->map(function (Conversation $c) {
                $last = $c->messages()->latest()->first();

                return [
                    'member' => $c->member?->full_name,
                    'coach' => $c->coach?->full_name,
                    'subject' => $c->subject,
                    'last_message_at' => $c->last_message_at?->toDateTimeString(),
                    'last_sender' => $last?->sender_type,
                    'waiting_hours' => $last && $last->sender_type === 'member'
                        ? (int) $last->created_at->diffInHours(now())
                        : null,
                ];
            })
            ->filter(fn (array $row) => $row['last_sender'] === 'member')
            ->sortByDesc('waiting_hours')
            ->values()
            ->all();
    }

    public function unreadMessages(): array
    {
        return $this->breakdown(ChatMessage::whereNull('read_at'), 'sender_type');
    }

    /** How many members could receive a push at all. */
    public function pushCoverage(): array
    {
        $withToken = PushToken::distinct()->count('user_id');
        $members = Member::active()->whereNotNull('user_id')->count();

        return [
            'devices' => PushToken::count(),
            'users_reachable' => $withToken,
            'active_members_with_account' => $members,
            'coverage_percent' => $members ? round($withToken / $members * 100, 1) : 0.0,
            'by_platform' => $this->breakdown(PushToken::query(), 'platform'),
        ];
    }

    public function aiInsights(): array
    {
        return AiInsight::whereNull('dismissed_at')
            ->orderByDesc('score')
            ->get()
            ->map(fn (AiInsight $i) => [
                'type' => $i->type,
                'title' => $i->title,
                'summary' => $i->summary,
                'severity' => $i->severity,
                'score' => (float) $i->score,
                'generated_at' => $i->generated_at?->toDateTimeString(),
            ])
            ->all();
    }

    public function aiInsightsBySeverity(): array
    {
        return $this->breakdown(AiInsight::whereNull('dismissed_at'), 'severity');
    }
}
