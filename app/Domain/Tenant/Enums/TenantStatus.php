<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enums;

enum TenantStatus: string
{
    case Active    = 'active';
    case Suspended = 'suspended';
    case Pending   = 'pending';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active    => 'Active',
            self::Suspended => 'Suspended',
            self::Pending   => 'Pending Activation',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isAccessible(): bool
    {
        return $this === self::Active;
    }
}
