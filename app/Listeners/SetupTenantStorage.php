<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Application\Services\StorageService;
use App\Events\TenantCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

final class SetupTenantStorage implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'default';
    public int $tries    = 3;

    public function __construct(
        private readonly StorageService $storageService,
    ) {}

    public function handle(TenantCreated $event): void
    {
        try {
            $this->storageService->setupTenantStorage($event->tenant->id);

            Log::info('Tenant S3 storage initialized', [
                'tenant_id' => $event->tenant->id,
                'slug'      => $event->tenant->slug,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to setup tenant S3 storage', [
                'tenant_id' => $event->tenant->id,
                'error'     => $e->getMessage(),
            ]);

            throw $e; // Trigger retry
        }
    }

    public function failed(TenantCreated $event, \Throwable $exception): void
    {
        Log::error('SetupTenantStorage listener permanently failed', [
            'tenant_id' => $event->tenant->id,
            'error'     => $exception->getMessage(),
        ]);
    }
}
