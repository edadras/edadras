<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the trail of who changed what. Every call is best effort: an audit
 * row that cannot be written must never take the request down with it.
 */
class Auditor
{
    /** Values that must never reach the log, whichever model they came from. */
    public const REDACTED = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'token', 'api_token', 'qr_token', 'gateway_token', 'nfc_uid', 'secret',
    ];

    /** Bookkeeping columns nobody wants to read in an audit trail. */
    protected const IGNORED = ['updated_at', 'created_at', 'deleted_at', 'last_login_at'];

    protected bool $enabled = true;

    public function __construct(private readonly TenantContext $tenancy) {}

    /** Turns the trail off for the duration of a callback (seeding, imports). */
    public function withoutAuditing(callable $callback): mixed
    {
        $previous = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $previous;
        }
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Records one action. `$subject` is the row it happened to, when there is
     * one; `$old` and `$new` are already-redacted attribute maps.
     */
    public function log(string $action, ?Model $subject = null, array $old = [], array $new = []): ?AuditLog
    {
        if (! $this->enabled) {
            return null;
        }

        try {
            $request = request();

            return AuditLog::create([
                'tenant_id' => $this->tenantIdFor($subject),
                'user_id' => Auth::id(),
                'action' => $action,
                'auditable_type' => $subject ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'old_values' => $this->clean($old) ?: null,
                'new_values' => $this->clean($new) ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            ]);
        } catch (Throwable $e) {
            // The action itself already succeeded; losing its trail is not a
            // reason to fail the caller.
            Log::warning('Audit log write failed: '.$e->getMessage(), ['action' => $action]);

            return null;
        }
    }

    /** Records a model that was just created. */
    public function created(Model $model): void
    {
        $this->log($this->action($model, 'created'), $model, [], $model->getAttributes());
    }

    /** Records only the columns that actually moved. */
    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());

        if ($changes === []) {
            return;
        }

        $before = collect($model->getOriginal())
            ->only(array_keys($changes))
            ->all();

        $this->log($this->action($model, 'updated'), $model, $before, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->log($this->action($model, 'deleted'), $model, $model->getOriginal(), []);
    }

    /** `member.updated`, `payment.created` — the model's own short name. */
    protected function action(Model $model, string $verb): string
    {
        return Str::snake(class_basename($model)).'.'.$verb;
    }

    protected function tenantIdFor(?Model $subject): ?int
    {
        $own = $subject?->getAttribute('tenant_id');

        return $own ? (int) $own : $this->tenancy->id();
    }

    /** Drops bookkeeping columns and masks anything secret. */
    protected function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (in_array($key, self::IGNORED, true)) {
                continue;
            }

            $clean[$key] = in_array($key, self::REDACTED, true)
                ? '••••'
                : (is_scalar($value) || $value === null || is_array($value) ? $value : (string) $value);
        }

        return $clean;
    }
}
