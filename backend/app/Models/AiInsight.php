<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A stored result of the AI module: churn risk, forecast, suggestion. */
class AiInsight extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'type', 'subject_type', 'subject_id', 'title', 'summary',
        'payload', 'score', 'severity', 'generated_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'score' => 'decimal:2',
            'generated_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
