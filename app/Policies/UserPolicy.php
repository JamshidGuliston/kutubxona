<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\User\Models\User;

final class UserPolicy
{
    public function viewAny(User $authUser): bool
    {
        return $authUser->hasRole(['tenant_admin', 'tenant_manager', 'super_admin']);
    }

    public function view(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) return true;

        if ($authUser->hasRole('super_admin')) return true;

        return $authUser->hasRole(['tenant_admin', 'tenant_manager'])
            && $authUser->tenant_id === $user->tenant_id;
    }

    public function create(User $authUser): bool
    {
        return $authUser->hasRole(['tenant_admin', 'super_admin']);
    }

    public function update(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) return true;

        if ($authUser->hasRole('super_admin')) return true;

        return $authUser->hasRole('tenant_admin')
            && $authUser->tenant_id === $user->tenant_id
            && !$user->hasRole('super_admin');
    }

    public function delete(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) return true;

        if ($authUser->hasRole('super_admin')) return true;

        return $authUser->hasRole('tenant_admin')
            && $authUser->tenant_id === $user->tenant_id
            && !$user->hasRole(['tenant_admin', 'super_admin']);
    }

    public function changeRole(User $authUser, User $user): bool
    {
        if ($authUser->hasRole('super_admin')) return true;

        return $authUser->hasRole('tenant_admin')
            && $authUser->tenant_id === $user->tenant_id
            && !$user->hasRole('super_admin');
    }
}
