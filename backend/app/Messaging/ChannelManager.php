<?php

namespace App\Messaging;

use App\Messaging\Channels\EmailChannel;
use App\Messaging\Channels\LogChannel;
use App\Messaging\Channels\PushChannel;
use App\Messaging\Channels\SmsChannel;
use App\Messaging\Channels\TelegramChannel;
use App\Messaging\Channels\WhatsAppChannel;
use App\Messaging\Contracts\MessageChannel;
use App\Models\Setting;
use App\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Hands out the right driver for a channel. Platform config is the floor and
 * a club's own settings sit on top, so one gym can bring its own SMS gateway
 * without touching anyone else's.
 *
 * Anything not configured falls back to the log driver, which means a campaign
 * always completes and nothing silently vanishes.
 */
class ChannelManager
{
    /** @var array<string, MessageChannel> */
    protected array $resolved = [];

    public function __construct(private readonly TenantContext $tenancy) {}

    public function channel(string $name): MessageChannel
    {
        $key = $this->tenancy->id().':'.$name;

        return $this->resolved[$key] ??= $this->resolve($name);
    }

    /** True when this club could actually reach anyone on the channel. */
    public function isLive(string $name): bool
    {
        return ! $this->channel($name) instanceof LogChannel;
    }

    /** @return array<string, bool> */
    public function status(): array
    {
        return collect(\App\Models\Campaign::CHANNELS)
            ->mapWithKeys(fn (string $channel) => [$channel => $this->isLive($channel)])
            ->all();
    }

    /** Forgets cached drivers, so a settings change takes effect at once. */
    public function flush(): void
    {
        $this->resolved = [];
    }

    protected function resolve(string $name): MessageChannel
    {
        $config = $this->configFor($name);

        $channel = match ($name) {
            'sms' => new SmsChannel($config),
            'whatsapp' => new WhatsAppChannel($config),
            'telegram' => new TelegramChannel($config),
            'email' => new EmailChannel($config),
            'push' => new PushChannel($config),
            default => throw new InvalidArgumentException("Unknown message channel [{$name}]."),
        };

        return $channel->isConfigured() ? $channel : new LogChannel($name);
    }

    /**
     * Platform defaults merged with whatever the club saved under the
     * `messaging.<channel>` settings key.
     *
     * @return array<string, mixed>
     */
    protected function configFor(string $name): array
    {
        $platform = (array) config("gymflow.messaging.channels.{$name}", []);

        if (! $this->tenancy->check()) {
            return $platform;
        }

        $override = Setting::where('tenant_id', $this->tenancy->id())
            ->where('key', "messaging.{$name}")
            ->value('value');

        return is_array($override) ? array_replace($platform, array_filter($override, fn ($v) => $v !== null && $v !== '')) : $platform;
    }
}
