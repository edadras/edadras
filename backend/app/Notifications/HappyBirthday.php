<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The club's birthday message. It goes to the member's app and their phone,
 * and carries the discount the club chose to offer with it, if any.
 */
class HappyBirthday extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Member $member,
        public readonly ?int $discountPercent = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'birthday',
            'member_id' => $this->member->id,
            'turns' => $this->member->birth_date?->age,
            'discount_percent' => $this->discountPercent,
            'title' => __('general.birthday_title'),
            'body' => $this->discountPercent
                ? __('general.birthday_body_gift', [
                    'name' => $this->member->first_name,
                    'percent' => $this->discountPercent,
                ])
                : __('general.birthday_body', ['name' => $this->member->first_name]),
        ];
    }
}
