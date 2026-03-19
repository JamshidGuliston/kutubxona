<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\BookUploaded;
use App\Events\TenantCreated;
use App\Listeners\ProcessBookAfterUpload;
use App\Listeners\SendTenantWelcomeEmail;
use App\Listeners\SetupTenantStorage;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event → listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // ── Tenant lifecycle ─────────────────────────────────────────────────
        TenantCreated::class => [
            SetupTenantStorage::class,
            SendTenantWelcomeEmail::class,
        ],

        // ── Book lifecycle ───────────────────────────────────────────────────
        BookUploaded::class => [
            ProcessBookAfterUpload::class,
        ],

        // ── User lifecycle ───────────────────────────────────────────────────
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
