<?php

namespace App\Messaging\Channels;

use App\Messaging\Contracts\MessageChannel;
use App\Messaging\DeliveryResult;
use App\Messaging\Recipient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A generic HTTP SMS gateway. Iranian and Turkish providers all expose the
 * same shape — a URL, a sender id and a couple of field names — so the club
 * configures those rather than the platform shipping one provider's SDK.
 */
class SmsChannel implements MessageChannel
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(Recipient $recipient, string $body, array $context = []): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::skipped('sms_not_configured');
        }

        if (! $recipient->reachableOn('sms')) {
            return DeliveryResult::skipped('no_phone');
        }

        $payload = array_merge(
            Arr::get($this->config, 'extra', []),
            [
                Arr::get($this->config, 'to_field', 'to') => $recipient->phone,
                Arr::get($this->config, 'text_field', 'text') => $body,
            ],
        );

        if ($sender = Arr::get($this->config, 'sender')) {
            $payload[Arr::get($this->config, 'from_field', 'from')] = $sender;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout((int) Arr::get($this->config, 'timeout', 15))
                ->asJson()
                ->post($this->config['url'], $payload);

            if ($response->failed()) {
                return DeliveryResult::failed('sms_http_'.$response->status());
            }

            return DeliveryResult::sent($this->reference($response->json()));
        } catch (Throwable $e) {
            return DeliveryResult::failed('sms_error: '.$e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return filled(Arr::get($this->config, 'url'));
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        $headers = Arr::get($this->config, 'headers', []);

        if ($token = Arr::get($this->config, 'token')) {
            $headers['Authorization'] = Arr::get($this->config, 'auth_scheme', 'Bearer').' '.$token;
        }

        return $headers;
    }

    protected function reference(mixed $body): ?string
    {
        $path = Arr::get($this->config, 'reference_path', 'messageId');

        return is_array($body) ? (string) Arr::get($body, $path, '') ?: null : null;
    }
}
