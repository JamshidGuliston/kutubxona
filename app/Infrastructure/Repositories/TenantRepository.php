<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

final class TenantRepository
{
    private const DOMAIN_CACHE_TTL = 600; // 10 minutes

    public function __construct(
        private readonly Tenant $model,
    ) {}

    public function create(array $data): Tenant
    {
        return $this->model->create($data);
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);
        $this->clearCache($tenant);
        return $tenant->fresh();
    }

    public function findById(int $id): ?Tenant
    {
        return Cache::remember(
            "tenant:id:{$id}",
            300,
            fn () => $this->model->with(['domains', 'plan'])->find($id)
        );
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return Cache::remember(
            "tenant:slug:{$slug}",
            self::DOMAIN_CACHE_TTL,
            fn () => $this->model->with(['domains', 'plan'])
                ->where('slug', $slug)
                ->first()
        );
    }

    /**
     * Find tenant by domain or subdomain (cached for performance).
     * This method is called on EVERY request — must be fast.
     */
    public function findByDomain(string $domain): ?Tenant
    {
        return Cache::remember(
            "tenant:domain:{$domain}",
            self::DOMAIN_CACHE_TTL,
            function () use ($domain): ?Tenant {
                $tenantDomain = TenantDomain::with('tenant.plan')
                    ->where('domain', $domain)
                    ->first();

                return $tenantDomain?->tenant;
            }
        );
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->with(['plan', 'primaryDomain']);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters): void {
                $q->where('name', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('slug', 'LIKE', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['plan_id'])) {
            $query->where('plan_id', $filters['plan_id']);
        }

        $query->latest();

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function count(): int
    {
        return $this->model->withoutGlobalScopes()->count();
    }

    public function clearCache(Tenant $tenant): void
    {
        Cache::forget("tenant:id:{$tenant->id}");
        Cache::forget("tenant:slug:{$tenant->slug}");

        // Clear domain caches
        foreach ($tenant->domains as $domain) {
            Cache::forget("tenant:domain:{$domain->domain}");
        }
    }
}
