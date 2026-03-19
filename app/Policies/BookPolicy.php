<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Book\Models\Book;
use App\Domain\User\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class BookPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view any books (list).
     * All authenticated users within the tenant can view books.
     */
    public function viewAny(?User $user): bool
    {
        return true; // Public access allowed (guest can browse)
    }

    /**
     * Determine if the user can view a specific book.
     */
    public function view(?User $user, Book $book): bool
    {
        // Published books are visible to all users in the same tenant
        if ($book->isPublished()) {
            return $user === null || $this->isSameTenant($user, $book);
        }

        // Draft/archived books only visible to admin/manager
        return $user !== null
            && $this->isSameTenant($user, $book)
            && $user->hasAnyRole(['tenant_admin', 'tenant_manager', 'super_admin']);
    }

    /**
     * Determine if the user can create books.
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasAnyRole(['tenant_admin', 'tenant_manager'])
            && app()->has('tenant')
            && $user->tenant_id === app('tenant')->id;
    }

    /**
     * Determine if the user can update a book.
     */
    public function update(User $user, Book $book): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->isSameTenant($user, $book)
            && $user->hasAnyRole(['tenant_admin', 'tenant_manager']);
    }

    /**
     * Determine if the user can delete a book.
     * Only tenant_admin and super_admin can delete (not managers).
     */
    public function delete(User $user, Book $book): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->isSameTenant($user, $book)
            && $user->hasRole('tenant_admin');
    }

    /**
     * Determine if the user can restore a soft-deleted book.
     */
    public function restore(User $user, Book $book): bool
    {
        return $this->delete($user, $book);
    }

    /**
     * Determine if the user can permanently delete a book.
     */
    public function forceDelete(User $user, Book $book): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can download a book file.
     */
    public function download(User $user, Book $book): bool
    {
        if (!$book->is_downloadable || !$book->isPublished()) {
            return false;
        }

        if (!$this->isSameTenant($user, $book)) {
            return false;
        }

        // Free books: any authenticated user can download
        if ($book->is_free) {
            return true;
        }

        // Paid books: admin/manager can always download
        // Regular users need subscription (future: implement subscription check here)
        return $user->hasAnyRole(['tenant_admin', 'tenant_manager', 'super_admin'])
            || $user->isActive(); // TODO: add subscription check for paid books
    }

    /**
     * Determine if the user can publish/archive a book.
     */
    public function publish(User $user, Book $book): bool
    {
        return $this->update($user, $book);
    }

    // ─── Helper ─────────────────────────────────────────────────────────────────

    private function isSameTenant(User $user, Book $book): bool
    {
        return $user->tenant_id === $book->tenant_id;
    }
}
