<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushToken extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'user_id', 'token', 'platform', 'device_name'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
