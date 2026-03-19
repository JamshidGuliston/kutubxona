<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\AudioBook\Models\AudioBook;
use App\Domain\User\Models\User;

final class AudioBookPolicy
{
    public function viewAny(?User $user): bool
    {
        return true; // Public catalog
    }

    public function view(?User $user, AudioBook $audioBook): bool
    {
        if ($audioBook->status !== 'published') {
            return $user !== null
                && $user->hasRole(['tenant_admin', 'tenant_manager', 'super_admin']);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['tenant_admin', 'tenant_manager', 'super_admin']);
    }

    public function update(User $user, AudioBook $audioBook): bool
    {
        if ($user->hasRole('super_admin')) return true;

        return $user->hasRole(['tenant_admin', 'tenant_manager'])
            && $user->tenant_id === $audioBook->tenant_id;
    }

    public function delete(User $user, AudioBook $audioBook): bool
    {
        if ($user->hasRole('super_admin')) return true;

        return $user->hasRole('tenant_admin')
            && $user->tenant_id === $audioBook->tenant_id;
    }

    public function stream(?User $user, AudioBook $audioBook): bool
    {
        return $audioBook->is_free
            || ($user !== null && $audioBook->tenant_id === $user->tenant_id);
    }
}
