<?php

namespace App\Messaging\Channels;

use App\Messaging\Contracts\MessageChannel;
use App\Messaging\DeliveryResult;
use App\Messaging\Recipient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram through the Bot API. A member is reachable once they have started
 * the club's bot, which is what gives us their chat id.
 */
class TelegramChannel implements MessageChannel
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(Recipient $recipient, string $body, array $context = []): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::skipped('telegram_not_configured');
        }

        $chatId = $recipient->telegramChatId ?: Arr::get($this->config, 'broadcast_chat_id');

        if (blank($chatId)) {
            return DeliveryResult::skipped('no_chat_id');
        }

        try {
            $response = Http::timeout((int) Arr::get($this->config, 'timeout', 15))
                ->post("https://api.telegram.org/bot{$this->config['token']}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $body,
                    'parse_mode' => Arr::get($this->config, 'parse_mode', 'HTML'),
                    'disable_web_page_preview' => true,
                ]);

            if ($response->failed()) {
                return DeliveryResult::failed('telegram_'.$response->status().': '.Arr::get($response->json(), 'description', ''));
            }

            return DeliveryResult::sent((string) Arr::get($response->json(), 'result.message_id'));
        } catch (Throwable $e) {
            return DeliveryResult::failed('telegram_error: '.$e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return filled(Arr::get($this->config, 'token'));
    }
}
