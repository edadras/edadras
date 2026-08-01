<?php

namespace App\Notifications\Channels;

use App\Messaging\ChannelManager;
use App\Messaging\Recipient;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Lets a notification say `push` alongside `database`, so a renewal reminder
 * lands on the member's phone as well as in their app.
 *
 * The notification supplies the wording through toPush(); anything without
 * that method falls back to its database payload, which already carries a
 * title and a body.
 */
class GymFlowPushChannel
{
    public function __construct(private readonly ChannelManager $channels) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $payload = method_exists($notification, 'toPush')
            ? $notification->toPush($notifiable)
            : (method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : []);

        $body = $payload['body'] ?? null;

        if (blank($body)) {
            return;
        }

        $recipient = Recipient::fromUser($notifiable->loadMissing('pushTokens'));

        if (! $recipient->reachableOn('push')) {
            return;
        }

        $this->channels->channel('push')->send($recipient, $body, [
            'title' => $payload['title'] ?? config('app.name'),
            'data' => collect($payload)->except(['title', 'body'])->all(),
        ]);
    }
}
