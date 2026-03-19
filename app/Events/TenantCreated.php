<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TenantCreated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User   $adminUser,
    ) {}
}
