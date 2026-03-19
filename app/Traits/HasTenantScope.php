<?php

declare(strict_types=1);

namespace App\Traits;

use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * HasTenantScope
 *
 * Apply this trait to any Eloquent model that requires tenant isolation.
 * It registers the TenantScope global scope and provides helpers for
 * querying across or within tenants.
 *
 * Usage:
 *   class MyModel extends Model {
 *       use HasTenantScope;
 *   }
 */
trait HasTenantScope
{
    /**
     * Boot the trait — adds TenantScope and auto-fills tenant_id on create.
     */
    protected static function bootHasTenantScope(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $model): void {
            if (empty($model->tenant_id)) {
                $tenantId = static::getCurrentTenantId();
                if ($tenantId !== null) {
                    $model->tenant_id = $tenantId;
                }
            }
        });
    }

    /**
     * Return the current tenant's ID from the IoC container.
     * Returns null when no tenant context is set (e.g., CLI, super-admin).
     */
    public static function getCurrentTenantId(): ?int
    {
        if (! app()->has('tenant')) {
            return null;
        }

        return (int) app('tenant')->id ?: null;
    }

    /**
     * Local query scope: bypass global TenantScope and filter by a specific tenant.
     *
     * Usage: Model::forTenant(5)->get()
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->where($this->getTable() . '.tenant_id', $tenantId);
    }

    /**
     * Local query scope: bypass TenantScope entirely (for super-admin queries).
     *
     * Usage: Model::withAllTenants()->get()
     */
    public function scopeWithAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
