<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Reports\Concerns\AggregatesRows;
use App\Tenancy\TenantContext;

/** Staff, permissions and the trail of who did what. */
class AdminReports
{
    use AggregatesRows;

    public function __construct(private readonly TenantContext $tenancy) {}

    public function staffRoster(): array
    {
        return User::whereDoesntHave('member')
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'roles' => $u->roles->pluck('slug')->implode(', '),
                'status' => $u->status,
                'two_factor' => (bool) $u->two_factor_enabled,
                'last_login_at' => $u->last_login_at?->toDateTimeString(),
            ])
            ->all();
    }

    /** Every role against every permission it holds. */
    public function rolesMatrix(): array
    {
        return Role::where('tenant_id', $this->tenancy->id())
            ->withCount('users')
            ->get()
            ->map(fn (Role $r) => [
                'role' => $r->slug,
                'name' => $r->name,
                'users' => $r->users_count,
                'permissions' => is_array($r->permissions) ? implode(', ', $r->permissions) : (string) $r->permissions,
            ])
            ->all();
    }

    public function staffByRole(): array
    {
        return Role::where('tenant_id', $this->tenancy->id())
            ->withCount('users')
            ->get()
            ->map(fn (Role $r) => ['label' => $r->slug, 'total' => $r->users_count])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** Staff who have not signed in for a long time, or ever. */
    public function dormantAccounts(int $days = 60): array
    {
        return User::whereDoesntHave('member')
            ->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays($days)))
            ->get()
            ->map(fn (User $u) => [
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'last_login_at' => $u->last_login_at?->toDateTimeString(),
                'days_since' => $u->last_login_at ? (int) $u->last_login_at->diffInDays(now()) : null,
            ])
            ->all();
    }

    public function auditActivity($from, $to): array
    {
        return $this->trail($from, $to)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at->toDateTimeString(),
                'by' => $log->user?->name,
                'action' => $log->action,
                'subject' => $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : null,
                'ip' => $log->ip_address,
            ])
            ->all();
    }

    public function auditByAction($from, $to): array
    {
        return $this->breakdown($this->trail($from, $to), 'action');
    }

    public function auditByUser($from, $to): array
    {
        return $this->trail($from, $to)
            ->with('user:id,name')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => [
                'user' => $rows->first()->user?->name ?? '—',
                'total' => $rows->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    public function loginHistory($from, $to): array
    {
        return $this->trail($from, $to)
            ->whereIn('action', ['auth.login', 'auth.logout'])
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at->toDateTimeString(),
                'action' => $log->action,
                'user' => $log->user?->name,
                'ip' => $log->ip_address,
                'device' => $log->user_agent,
            ])
            ->all();
    }

    /** Refused sign-ins, grouped by address — the brute force early warning. */
    public function failedLogins($from, $to): array
    {
        return $this->trail($from, $to)
            ->whereIn('action', ['auth.login_failed', 'auth.login_refused'])
            ->get()
            ->groupBy('ip_address')
            ->map(fn ($rows, $ip) => [
                'ip' => (string) ($ip ?: '—'),
                'attempts' => $rows->count(),
                'last_attempt' => $rows->max('created_at')?->toDateTimeString(),
            ])
            ->sortByDesc('attempts')
            ->values()
            ->all();
    }

    public function dataChanges($from, $to): array
    {
        return $this->breakdown(
            $this->trail($from, $to)->whereNotNull('auditable_type'),
            'auditable_type'
        );
    }

    protected function trail($from, $to)
    {
        return AuditLog::where('tenant_id', $this->tenancy->id())
            ->whereBetween('created_at', [$from, $to]);
    }
}
