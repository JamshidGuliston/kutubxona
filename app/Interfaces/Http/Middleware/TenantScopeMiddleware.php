<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Infrastructure\Scopes\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TenantScopeMiddleware
 *
 * Ensures that the TenantScope global scope is applied to all Eloquent
 * queries in the current request lifecycle.
 *
 * This is a safety net — models already apply TenantScope in booted().
 * This middleware verifies the tenant context is properly set before
 * any Eloquent queries run.
 *
 * Should be applied AFTER TenantMiddleware in the middleware stack.
 */
final class TenantScopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->has('tenant')) {
            // TenantMiddleware should have rejected the request already.
            // This is a safety check for routes that may have misconfigured middleware.
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Tenant context not established.',
                'code'    => 'NO_TENANT_CONTEXT',
            ], 400);
        }

        $tenant = app('tenant');

        // Set Spatie Permission team context for RBAC
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        }

        return $next($request);
    }
}
