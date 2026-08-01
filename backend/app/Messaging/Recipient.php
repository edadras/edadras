<?php

namespace App\Messaging;

use App\Models\Member;
use App\Models\User;

/** Whoever a message is going to, with every address a channel might need. */
final class Recipient
{
    public function __construct(
        public readonly ?int $memberId = null,
        public readonly ?int $userId = null,
        public readonly ?string $name = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $telegramChatId = null,
        /** @var array<int, string> */
        public readonly array $pushTokens = [],
        public readonly string $locale = 'fa',
    ) {}

    public static function fromMember(Member $member): self
    {
        $user = $member->user;

        return new self(
            memberId: $member->id,
            userId: $user?->id,
            name: $member->full_name,
            phone: $member->phone,
            email: $member->email ?: $user?->email,
            telegramChatId: $member->telegram_chat_id,
            pushTokens: $user?->pushTokens->pluck('token')->all() ?? [],
            locale: $user?->locale ?? config('gymflow.default_locale'),
        );
    }

    public static function fromUser(User $user): self
    {
        return new self(
            userId: $user->id,
            name: $user->name,
            phone: $user->phone,
            email: $user->email,
            pushTokens: $user->pushTokens->pluck('token')->all(),
            locale: $user->locale ?? config('gymflow.default_locale'),
        );
    }

    /** Whether this recipient can be reached at all on a given channel. */
    public function reachableOn(string $channel): bool
    {
        return match ($channel) {
            'sms', 'whatsapp' => filled($this->phone),
            'email' => filled($this->email),
            'telegram' => filled($this->telegramChatId),
            'push' => $this->pushTokens !== [],
            default => false,
        };
    }
}
