<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Repositories;

use App\Domain\User\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface UserRepositoryInterface
{
    /**
     * Find a user by primary key, regardless of tenant scope.
     */
    public function findById(int $id): ?User;

    /**
     * Find a user by primary key within a specific tenant.
     */
    public function findByIdForTenant(int $id, int $tenantId): ?User;

    /**
     * Find a user by email address (across all tenants — used for auth).
     */
    public function findByEmail(string $email): ?User;

    /**
     * Find a user by email scoped to a specific tenant.
     */
    public function findByEmailForTenant(string $email, int $tenantId): ?User;

    /**
     * Return paginated users belonging to a specific tenant.
     *
     * @param array{
     *   search?: string,
     *   status?: string,
     *   role?: string,
     *   sort?: string,
     *   order?: 'asc'|'desc',
     * } $filters
     */
    public function getByTenant(int $tenantId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Return all users for a tenant as a Collection (for exports / bulk ops).
     */
    public function getAllByTenant(int $tenantId): Collection;

    /**
     * Create a new user record.
     */
    public function create(array $data): User;

    /**
     * Update a user by primary key.
     */
    public function update(int $id, array $data): User;

    /**
     * Soft-delete a user.
     */
    public function delete(int $id): bool;

    /**
     * Count active users for a tenant.
     */
    public function countActiveByTenant(int $tenantId): int;
}
