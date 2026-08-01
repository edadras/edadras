<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Roles are per tenant so a club can rename or fine tune them. The seeded
 * set is owner, manager, reception, coach, cashier, accountant, member.
 */
class Role extends Model
{
    use HasFactory, HasTranslations;

    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const RECEPTION = 'reception';

    public const COACH = 'coach';

    public const CASHIER = 'cashier';

    public const ACCOUNTANT = 'accountant';

    public const MEMBER = 'member';

    protected $fillable = ['tenant_id', 'slug', 'name', 'permissions', 'is_system'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'permissions' => 'collection',
            'is_system' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Supports exact matches and wildcards, so "members.*" grants
     * "members.create" and "members.delete".
     */
    public function grants(string $permission): bool
    {
        $granted = $this->permissions ?? collect();

        if ($granted->contains('*')) {
            return true;
        }

        if ($granted->contains($permission)) {
            return true;
        }

        [$group] = explode('.', $permission, 2);

        return $granted->contains($group.'.*');
    }
}
