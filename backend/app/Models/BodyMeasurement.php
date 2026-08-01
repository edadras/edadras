<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BodyMeasurement extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'member_id', 'recorded_by', 'measured_at', 'weight', 'height',
        'bmi', 'fat_percent', 'muscle_mass', 'arm', 'chest', 'waist', 'hip',
        'thigh', 'calf', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'date',
            'weight' => 'decimal:2',
            'bmi' => 'decimal:2',
            'fat_percent' => 'decimal:2',
            'muscle_mass' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // BMI is always derived, never trusted from the client.
        static::saving(function (BodyMeasurement $measurement) {
            if ($measurement->weight && $measurement->height) {
                $meters = $measurement->height / 100;
                $measurement->bmi = round($measurement->weight / ($meters ** 2), 2);
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
