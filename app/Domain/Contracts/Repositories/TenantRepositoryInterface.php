<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Repositories;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TenantRepositoryInterface
{
    /**
     * Find a tenant by its primary key.
     */
    public function findById(int $id): ?Tenant;

    /**
     * Find a tenant by a custom domain (stored in tenant_domains table).
     */
    public function findByDomain(string $domain): ?Tenant;

    /**
     * Find a tenant by its URL slug.
     */
    public function findBySlug(string $slug): ?Tenant;

    /**
     * Create a new tenant record.
     */
    public function create(array $data): Tenant;

    /**
     * Update a tenant by its primary key.
     */
    public function update(int $id, array $data): Tenant;

    /**
     * Suspend a tenant (set status to suspended, record suspended_at timestamp).
     */
    public function suspend(int $id): bool;

    /**
     * Activate a previously suspended or pending tenant.
     */
    public function activate(int $id): bool;

    /**
     * Return a paginated list of all tenants with optional filters.
     *
     * @param array{
     *   status?: string,
     *   search?: string,
     *   plan_id?: int,
     *   sort?: string,
     *   order?: 'asc'|'desc',
     * } $filters
     */
    public function getAll(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Return aggregate statistics for a single tenant.
     *
     * @return array{
     *   total_users: int,
     *   active_users: int,
     *   total_books: int,
     *   published_books: int,
     *   storage_used_bytes: int,
     *   storage_quota_bytes: int,
     *   storage_percent: float,
     * }
     */
    public function getStats(int $id): array;
}
