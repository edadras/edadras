<?php

namespace App\Payments;

use App\Models\Setting;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Gateways\SandboxGateway;
use App\Payments\Gateways\StripeGateway;
use App\Payments\Gateways\ZarinpalGateway;
use App\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Resolves the payment gateway a club actually uses. Same shape as the
 * messaging manager: platform config underneath, the club's own settings on
 * top, and a sandbox that lets the whole flow be walked through unconfigured.
 */
class GatewayManager
{
    public const GATEWAYS = ['zarinpal', 'stripe', 'sandbox'];

    /** @var array<string, PaymentGateway> */
    protected array $resolved = [];

    public function __construct(private readonly TenantContext $tenancy) {}

    /** The club's chosen gateway, or the platform default. */
    public function default(): PaymentGateway
    {
        return $this->gateway($this->defaultName());
    }

    public function defaultName(): string
    {
        $chosen = $this->tenancy->check()
            ? Setting::where('tenant_id', $this->tenancy->id())->where('key', 'payments.gateway')->value('value')
            : null;

        $name = is_array($chosen) ? ($chosen['name'] ?? null) : $chosen;

        return in_array($name, self::GATEWAYS, true)
            ? $name
            : (string) config('gymflow.payments.default', 'sandbox');
    }

    public function gateway(string $name): PaymentGateway
    {
        $key = $this->tenancy->id().':'.$name;

        return $this->resolved[$key] ??= $this->resolve($name);
    }

    public function isLive(): bool
    {
        return ! $this->default() instanceof SandboxGateway;
    }

    public function flush(): void
    {
        $this->resolved = [];
    }

    protected function resolve(string $name): PaymentGateway
    {
        $config = $this->configFor($name);

        $gateway = match ($name) {
            'zarinpal' => new ZarinpalGateway($config),
            'stripe' => new StripeGateway($config),
            'sandbox' => new SandboxGateway,
            default => throw new InvalidArgumentException("Unknown payment gateway [{$name}]."),
        };

        return $gateway->isConfigured() ? $gateway : new SandboxGateway;
    }

    /** @return array<string, mixed> */
    protected function configFor(string $name): array
    {
        $platform = (array) config("gymflow.payments.gateways.{$name}", []);

        if (! $this->tenancy->check()) {
            return $platform;
        }

        $override = Setting::where('tenant_id', $this->tenancy->id())
            ->where('key', "payments.{$name}")
            ->value('value');

        return is_array($override)
            ? array_replace($platform, array_filter($override, fn ($v) => $v !== null && $v !== ''))
            : $platform;
    }
}
