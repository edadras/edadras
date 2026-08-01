<?php

namespace App\Messaging\Contracts;

use App\Messaging\DeliveryResult;
use App\Messaging\Recipient;

interface MessageChannel
{
    /**
     * Sends one message. `$context` carries whatever the channel needs beyond
     * the body — a subject for email, a title for push, the club it came from.
     *
     * @param  array<string, mixed>  $context
     */
    public function send(Recipient $recipient, string $body, array $context = []): DeliveryResult;

    /** False when this club has not configured the channel yet. */
    public function isConfigured(): bool;
}
