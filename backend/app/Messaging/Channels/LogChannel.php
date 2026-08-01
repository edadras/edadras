<?php

namespace App\Messaging\Channels;

use App\Messaging\Contracts\MessageChannel;
use App\Messaging\DeliveryResult;
use App\Messaging\Recipient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default for every channel. It writes the message to the log instead of
 * sending it, so a club that has not brought its own provider yet can run
 * campaigns end to end without anything leaving the building.
 */
class LogChannel implements MessageChannel
{
    public function __construct(private readonly string $channel = 'log') {}

    public function send(Recipient $recipient, string $body, array $context = []): DeliveryResult
    {
        Log::channel(config('gymflow.messaging.log_channel', 'stack'))->info('Campaign message', [
            'channel' => $this->channel,
            'to' => $recipient->phone ?? $recipient->email ?? $recipient->telegramChatId ?? 'device',
            'name' => $recipient->name,
            'body' => Str::limit($body, 300),
        ]);

        return DeliveryResult::sent('log:'.Str::uuid());
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
