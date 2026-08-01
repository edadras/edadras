<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Database driven i18n string. A tenant row overrides the global one. */
class Translation extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'locale', 'group', 'key', 'value'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
