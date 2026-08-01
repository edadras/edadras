<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Shared exercise library. A null tenant_id row is a platform default. */
class Exercise extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'tenant_id', 'name', 'muscle_group', 'equipment', 'media_url', 'instructions',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'instructions' => 'array',
        ];
    }
}
