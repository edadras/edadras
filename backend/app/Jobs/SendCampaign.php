<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\CampaignDispatcher;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends a campaign off the request thread. A queued job carries no tenant
 * context of its own, so it restores the campaign's club before it starts —
 * otherwise the audience query would come back empty or, worse, wide.
 */
class SendCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public readonly int $campaignId, public readonly int $tenantId) {}

    public function handle(TenantContext $tenancy, CampaignDispatcher $dispatcher): void
    {
        $tenancy->runAs($this->tenantId, function () use ($dispatcher) {
            $campaign = Campaign::find($this->campaignId);

            if (! $campaign || $campaign->status === 'sent') {
                return;
            }

            $dispatcher->send($campaign);
        });
    }

    public function failed(Throwable $e): void
    {
        app(TenantContext::class)->runAs($this->tenantId, function () {
            Campaign::where('id', $this->campaignId)->update(['status' => 'failed']);
        });
    }
}
