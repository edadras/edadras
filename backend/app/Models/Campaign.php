<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bulk message over SMS, WhatsApp, Telegram, email or push. */
class Campaign extends Model
{
    use BelongsToTenant, HasFactory;

    public const CHANNELS = ['sms', 'whatsapp', 'telegram', 'email', 'push'];

    protected $fillable = [
        'tenant_id', 'title', 'channel', 'body', 'audience', 'scheduled_at',
        'sent_at', 'status', 'recipients_count', 'delivered_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
