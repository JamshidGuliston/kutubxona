<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\User\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class UserRepository
{
    public function __construct(
        private readonly User $model,
    ) {}

    public function create(array $data): User
    {
        return $this->model->create($data);
    }

    public function findById(int $id): ?User
    {
        return $this->model->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    /**
     * Find a user by email within a specific tenant (for login).
     */
    public function findByEmailAndTenant(string $email, int $tenantId): ?User
    {
        return $this->model
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->first();
    }

    /**
     * Check if email exists within a tenant.
     */
    public function existsByEmail(string $email, int $tenantId): bool
    {
        return $this->model
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->exists();
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->fresh();
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }

    public function paginate(
        int $tenantId,
        array $filters = [],
        int $page = 1,
        int $perPage = 20
    ): LengthAwarePaginator {
        $query = $this->model
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('roles');

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters): void {
                $q->where('name', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('email', 'LIKE', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['role'])) {
            $query->role($filters['role']);
        }

        $query->latest();

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function countByTenant(int $tenantId): int
    {
        return $this->model
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();
    }
}
