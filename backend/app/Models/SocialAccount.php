<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A provider account a user has connected to sign in with. */
class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'provider', 'provider_user_id', 'email', 'name', 'avatar',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
