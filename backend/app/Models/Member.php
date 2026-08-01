<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Member extends Model
{
    use BelongsToTenant, HasFactory, RecordsAudit, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'user_id', 'code', 'first_name', 'last_name', 'national_id',
        'passport_no', 'phone', 'email', 'gender', 'birth_date', 'height', 'weight',
        'blood_type', 'diseases', 'allergies', 'notes', 'photo_path', 'qr_token',
        'nfc_uid', 'telegram_chat_id', 'emergency_name', 'emergency_phone',
        'status', 'joined_at',
    ];

    /**
     * Mirrors the column defaults so a freshly created instance behaves the
     * same as one loaded back from the database.
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'joined_at' => 'date',
            'weight' => 'decimal:2',
            // Health notes and travel documents are encrypted at rest: a
            // stolen database dump should not read as a medical record.
            // national_id stays in the clear on purpose — reception searches
            // by it, and an encrypted column cannot be searched with LIKE.
            'passport_no' => 'encrypted',
            'diseases' => 'encrypted',
            'allergies' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Member $member) {
            $member->qr_token ??= Str::lower(Str::random(40));
            $member->joined_at ??= now()->toDateString();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMembership(): HasOne
    {
        return $this->hasOne(Membership::class)->where('status', 'active')->latestOfMany();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(BodyMeasurement::class);
    }

    public function workoutPlans(): HasMany
    {
        return $this->hasMany(WorkoutPlan::class);
    }

    public function nutritionPlans(): HasMany
    {
        return $this->hasMany(NutritionPlan::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ClassBooking::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('national_id', 'like', "%{$term}%");
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
