<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class TenantPolicy
{
    use HandlesAuthorization;

    /**
     * Only super admins can see all tenants.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Super admins can view any tenant.
     * Tenant admins can view their own tenant.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasRole('tenant_admin') && $user->tenant_id === $tenant->id;
    }

    /**
     * Only super admins can create tenants.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Super admins can update any tenant.
     * Tenant admins can update only their own tenant (limited fields).
     */
    public function update(User $user, Tenant $tenant): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasRole('tenant_admin') && $user->tenant_id === $tenant->id;
    }

    /**
     * Only super admins can delete tenants.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Only super admins can suspend tenants.
     */
    public function suspend(User $user, Tenant $tenant): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Only super admins can activate tenants.
     */
    public function activate(User $user, Tenant $tenant): bool
    {
        return $user->hasRole('super_admin');
    }
}
