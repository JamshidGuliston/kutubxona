<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TenantCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

final class SendTenantWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'emails';
    public int $tries    = 3;

    public function handle(TenantCreated $event): void
    {
        try {
            // TODO: Dispatch welcome email notification to tenant owner
            Log::info('Tenant welcome email queued', [
                'tenant_id' => $event->tenant->id,
                'slug'      => $event->tenant->slug,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send tenant welcome email', [
                'tenant_id' => $event->tenant->id,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(TenantCreated $event, \Throwable $exception): void
    {
        Log::error('SendTenantWelcomeEmail listener permanently failed', [
            'tenant_id' => $event->tenant->id,
            'error'     => $exception->getMessage(),
        ]);
    }
}
