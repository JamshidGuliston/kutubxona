<?php

declare(strict_types=1);

namespace App\Infrastructure\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Eloquent scope that automatically restricts all queries
 * to the currently active tenant.
 *
 * Applied to all domain models that extend TenantModel.
 * Can be bypassed for super-admin queries via withoutGlobalScope(TenantScope::class).
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!app()->has('tenant')) {
            // No tenant context (e.g., CLI commands, super-admin without tenant)
            return;
        }

        $tenant = app('tenant');

        // Apply WHERE tenant_id = ? on the model's table
        $builder->where($model->getTable() . '.tenant_id', '=', $tenant->id);
    }

    /**
     * Extend the query builder with additional macros.
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withAllTenants', function (Builder $builder): Builder {
            return $builder->withoutGlobalScope($this);
        });

        $builder->macro('forTenant', function (Builder $builder, int $tenantId): Builder {
            return $builder->withoutGlobalScope($this)->where('tenant_id', $tenantId);
        });
    }
}
