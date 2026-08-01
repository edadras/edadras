<?php

namespace App\Notifications;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The automatic renewal reminder. It is stored in the database so the member
 * app can show it, and the club's push provider picks it up from there.
 */
class MembershipExpiring extends Notification
{
    use Queueable;

    public function __construct(public readonly Membership $membership) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'membership_expiring',
            'membership_id' => $this->membership->id,
            'ends_at' => $this->membership->ends_at?->toDateString(),
            'days_remaining' => $this->membership->daysRemaining(),
            'remaining_sessions' => $this->membership->remaining_sessions,
            'title' => __('ai.campaign_expiring_title'),
            'body' => __('ai.campaign_expiring_body'),
        ];
    }
}
