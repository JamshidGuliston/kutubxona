<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\User\Models\User;
use App\Events\TenantCreated;
use App\Infrastructure\Repositories\TenantRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class TenantService
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
    ) {}

    /**
     * Create a new tenant with admin user and initial domain.
     *
     * @param array{
     *   name: string,
     *   slug: string,
     *   plan_id: ?int,
     *   admin_name: string,
     *   admin_email: string,
     *   admin_password: string,
     *   settings: array,
     *   custom_domain: ?string
     * } $data
     */
    public function createTenant(array $data): Tenant
    {
        return DB::transaction(function () use ($data): Tenant {
            // 1. Create the tenant record
            $tenant = $this->tenantRepository->create([
                'name'           => $data['name'],
                'slug'           => $data['slug'],
                'status'         => TenantStatus::Pending->value,
                'plan_id'        => $data['plan_id'] ?? null,
                'settings'       => array_merge([
                    'locale'   => 'uz',
                    'theme'    => 'default',
                    'features' => [
                        'audiobooks' => true,
                        'reviews'    => true,
                        'downloads'  => true,
                    ],
                ], $data['settings'] ?? []),
                'storage_quota'  => $this->getStorageQuotaForPlan($data['plan_id'] ?? null),
                'max_users'      => $this->getMaxUsersForPlan($data['plan_id'] ?? null),
                'max_books'      => $this->getMaxBooksForPlan($data['plan_id'] ?? null),
                'trial_ends_at'  => now()->addDays(14),
            ]);

            // 2. Create primary subdomain
            TenantDomain::create([
                'tenant_id'  => $tenant->id,
                'domain'     => $tenant->slug . '.' . config('app.base_domain', 'kutubxona.uz'),
                'type'       => 'subdomain',
                'is_primary' => true,
                'ssl_status' => 'active',
                'verified_at'=> now(),
            ]);

            // 3. Create custom domain if provided
            if (!empty($data['custom_domain'])) {
                TenantDomain::create([
                    'tenant_id'  => $tenant->id,
                    'domain'     => $data['custom_domain'],
                    'type'       => 'custom',
                    'is_primary' => false,
                    'ssl_status' => 'pending',
                ]);
            }

            // 4. Create admin user
            // Temporarily bind tenant for model creation
            app()->instance('tenant', $tenant);

            $adminUser = User::create([
                'tenant_id'         => $tenant->id,
                'name'              => $data['admin_name'],
                'email'             => $data['admin_email'],
                'password'          => Hash::make($data['admin_password']),
                'email_verified_at' => now(),
                'locale'            => $tenant->getSetting('locale', 'uz'),
                'status'            => 'active',
            ]);

            // 5. Assign tenant_admin role (within tenant team context)
            setPermissionsTeamId($tenant->id);
            $adminUser->assignRole('tenant_admin');

            // 6. Create subscription if plan provided
            if (!empty($data['plan_id'])) {
                $tenant->subscription()->create([
                    'plan_id'             => $data['plan_id'],
                    'status'              => 'trialing',
                    'billing_cycle'       => 'monthly',
                    'amount'              => 0,
                    'currency'            => 'USD',
                    'trial_ends_at'       => now()->addDays(14),
                    'current_period_start'=> now(),
                    'current_period_end'  => now()->addDays(14),
                ]);
            }

            // 7. Activate tenant
            $tenant->update(['status' => TenantStatus::Active->value]);

            // 8. Fire event (listeners will setup storage and send welcome email)
            event(new TenantCreated($tenant, $adminUser));

            return $tenant->fresh(['domains', 'plan']);
        });
    }

    /**
     * Update an existing tenant's details.
     */
    public function updateTenant(Tenant $tenant, array $data): Tenant
    {
        $updateData = array_filter([
            'name'     => $data['name'] ?? null,
            'plan_id'  => $data['plan_id'] ?? null,
            'settings' => isset($data['settings'])
                ? array_merge($tenant->settings ?? [], $data['settings'])
                : null,
        ], fn ($v) => $v !== null);

        if (!empty($updateData)) {
            $tenant->update($updateData);
        }

        return $tenant->fresh();
    }

    /**
     * Suspend a tenant (blocks all access).
     */
    public function suspendTenant(Tenant $tenant, string $reason = ''): Tenant
    {
        $tenant->update([
            'status'       => TenantStatus::Suspended->value,
            'suspended_at' => now(),
            'metadata'     => array_merge($tenant->metadata ?? [], [
                'suspension_reason' => $reason,
                'suspended_by'      => auth()->id(),
            ]),
        ]);

        // Invalidate all tenant user sessions
        $this->invalidateTenantSessions($tenant);

        return $tenant->fresh();
    }

    /**
     * Reactivate a suspended tenant.
     */
    public function activateTenant(Tenant $tenant): Tenant
    {
        $tenant->update([
            'status'       => TenantStatus::Active->value,
            'suspended_at' => null,
        ]);

        return $tenant->fresh();
    }

    /**
     * Soft-delete (cancel) a tenant.
     */
    public function cancelTenant(Tenant $tenant): bool
    {
        $tenant->update(['status' => TenantStatus::Cancelled->value]);
        $this->invalidateTenantSessions($tenant);
        return $tenant->delete();
    }

    /**
     * Resolve tenant by domain or subdomain.
     */
    public function getTenantByDomain(string $domain): ?Tenant
    {
        return $this->tenantRepository->findByDomain($domain);
    }

    /**
     * Resolve tenant by slug.
     */
    public function getTenantBySlug(string $slug): ?Tenant
    {
        return $this->tenantRepository->findBySlug($slug);
    }

    /**
     * Get aggregated stats for a tenant.
     */
    public function getTenantStats(Tenant $tenant): array
    {
        // Scope to this specific tenant
        $tenantId = $tenant->id;

        $bookCount  = \App\Domain\Book\Models\Book::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->count();
        $userCount  = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->count();
        $downloads  = \App\Domain\Book\Models\Book::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->sum('download_count');

        return [
            'total_books'     => $bookCount,
            'total_users'     => $userCount,
            'total_downloads' => $downloads,
            'storage_used_mb' => $tenant->storage_used_mb,
            'storage_quota_mb'=> $tenant->storage_quota_mb,
            'storage_percent' => $tenant->storage_usage_percent,
            'is_on_trial'     => $tenant->isOnTrial(),
            'trial_ends_at'   => $tenant->trial_ends_at?->toIso8601String(),
        ];
    }

    /**
     * List all tenants with filters.
     */
    public function getAllTenants(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->tenantRepository->paginate($filters, $page, $perPage);
    }

    /**
     * Blacklist all JWT tokens for users of this tenant.
     */
    private function invalidateTenantSessions(Tenant $tenant): void
    {
        // Using Redis pattern deletion for all tenant users' refresh tokens
        $redis = app('redis');
        $pattern = "refresh:{$tenant->id}:*";
        $keys = $redis->keys($pattern);
        if (!empty($keys)) {
            $redis->del($keys);
        }
    }

    private function getStorageQuotaForPlan(?int $planId): int
    {
        if (!$planId) return 1073741824; // 1GB default
        $plan = \App\Domain\Billing\Models\Plan::find($planId);
        return $plan?->storage_quota ?? 1073741824;
    }

    private function getMaxUsersForPlan(?int $planId): int
    {
        if (!$planId) return 10;
        $plan = \App\Domain\Billing\Models\Plan::find($planId);
        return $plan?->max_users ?? 10;
    }

    private function getMaxBooksForPlan(?int $planId): int
    {
        if (!$planId) return 100;
        $plan = \App\Domain\Billing\Models\Plan::find($planId);
        return $plan?->max_books ?? 100;
    }
}
