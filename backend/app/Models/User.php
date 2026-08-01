<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Staff and member accounts. A user with a null tenant_id and
 * is_super_admin = true operates the platform panel.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, RecordsAudit, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'password', 'avatar_path',
        'locale', 'is_super_admin', 'status', 'two_factor_enabled', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'two_factor_enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function coach(): HasOne
    {
        return $this->hasOne(Coach::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->slug === $slug);
    }

    /** Super admins bypass the permission matrix entirely. */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->grants($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        return $this->roles
            ->flatMap(fn (Role $role) => ($role->permissions ?? collect())->all())
            ->unique()
            ->values()
            ->all();
    }
}
