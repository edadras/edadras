<?php

namespace App\Messaging\Channels;

use App\Messaging\Contracts\MessageChannel;
use App\Messaging\DeliveryResult;
use App\Messaging\Recipient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp through Meta's Cloud API. Outside the 24 hour service window Meta
 * only accepts approved templates, so the club can name one and the body is
 * passed as its first parameter.
 */
class WhatsAppChannel implements MessageChannel
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(Recipient $recipient, string $body, array $context = []): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::skipped('whatsapp_not_configured');
        }

        if (! $recipient->reachableOn('whatsapp')) {
            return DeliveryResult::skipped('no_phone');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            Arr::get($this->config, 'api_version', 'v21.0'),
            $this->config['phone_number_id'],
        );

        try {
            $response = Http::withToken($this->config['token'])
                ->timeout((int) Arr::get($this->config, 'timeout', 15))
                ->post($url, $this->payload($recipient, $body));

            if ($response->failed()) {
                return DeliveryResult::failed('whatsapp_'.$response->status().': '.Arr::get($response->json(), 'error.message', ''));
            }

            return DeliveryResult::sent(Arr::get($response->json(), 'messages.0.id'));
        } catch (Throwable $e) {
            return DeliveryResult::failed('whatsapp_error: '.$e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return filled(Arr::get($this->config, 'token')) && filled(Arr::get($this->config, 'phone_number_id'));
    }

    /** @return array<string, mixed> */
    protected function payload(Recipient $recipient, string $body): array
    {
        $to = ltrim((string) $recipient->phone, '+');

        if ($template = Arr::get($this->config, 'template')) {
            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => Arr::get($this->config, 'template_language', $recipient->locale)],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => [['type' => 'text', 'text' => $body]],
                    ]],
                ],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ];
    }
}
