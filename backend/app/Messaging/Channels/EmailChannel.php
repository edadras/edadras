<?php

namespace App\Messaging\Channels;

use App\Mail\ClubMessage;
use App\Messaging\Contracts\MessageChannel;
use App\Messaging\DeliveryResult;
use App\Messaging\Recipient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Email over whatever mailer the deployment already configured. */
class EmailChannel implements MessageChannel
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function send(Recipient $recipient, string $body, array $context = []): DeliveryResult
    {
        if (! $recipient->reachableOn('email')) {
            return DeliveryResult::skipped('no_email');
        }

        $subject = Arr::get($context, 'subject') ?: Arr::get($context, 'title', config('app.name'));

        try {
            Mail::to($recipient->email, $recipient->name)
                ->send(new ClubMessage(
                    heading: $subject,
                    body: $body,
                    clubName: Arr::get($context, 'club_name'),
                    logoUrl: Arr::get($context, 'logo_url'),
                    brandColor: Arr::get($context, 'brand_color', config('gymflow.brand.primary')),
                ));

            return DeliveryResult::sent();
        } catch (Throwable $e) {
            return DeliveryResult::failed('email_error: '.$e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return config('mail.default') !== null;
    }
}
