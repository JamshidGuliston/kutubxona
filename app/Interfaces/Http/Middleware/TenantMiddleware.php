<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Infrastructure\Repositories\TenantRepository;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * TenantMiddleware
 *
 * Detects the current tenant from the incoming request using a priority chain:
 *   1. X-Tenant-ID header  (for mobile apps, Postman, API clients)
 *   2. JWT token "tid" claim (for authenticated API requests)
 *   3. Subdomain            (e.g., library1.kutubxona.uz)
 *   4. Custom domain        (e.g., library.myschool.edu)
 *
 * Once detected, binds the Tenant to the IoC container as app('tenant').
 * Subsequent middleware and services retrieve it via app('tenant').
 */
final class TenantMiddleware
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if ($tenant === null) {
            return $this->tenantNotFound();
        }

        // Check tenant accessibility
        $response = $this->assertTenantAccessible($tenant);
        if ($response !== null) {
            return $response;
        }

        // Bind tenant to IoC container
        app()->instance('tenant', $tenant);

        // Switch to dedicated DB connection if premium tenant configured
        if ($tenant->hasDedicatedConnection()) {
            $this->switchDatabaseConnection($tenant);
        }

        // Add tenant info to request for convenience
        $request->merge(['_tenant_id' => $tenant->id]);

        // Structured log context
        Log::withContext([
            'tenant_id'   => $tenant->id,
            'tenant_slug' => $tenant->slug,
        ]);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Reset database connection after request if switched
        if (app()->has('tenant') && app('tenant')->hasDedicatedConnection()) {
            \Illuminate\Support\Facades\DB::setDefaultConnection(
                config('database.default')
            );
        }
    }

    // ─── Tenant Detection Chain ───────────────────────────────────────────────────

    private function resolveTenant(Request $request): ?Tenant
    {
        return $this->fromHeader($request)
            ?? $this->fromJwtClaim($request)
            ?? $this->fromSubdomain($request)
            ?? $this->fromCustomDomain($request);
    }

    /**
     * Detect tenant from X-Tenant-ID header.
     * Used by mobile apps and API clients that don't use domain-based routing.
     */
    private function fromHeader(Request $request): ?Tenant
    {
        $tenantId = $request->header('X-Tenant-ID');
        if (empty($tenantId) || !is_numeric($tenantId)) {
            return null;
        }

        return $this->tenantRepository->findById((int) $tenantId);
    }

    /**
     * Detect tenant from JWT "tid" claim.
     * Most efficient for already-authenticated requests.
     */
    private function fromJwtClaim(Request $request): ?Tenant
    {
        $authHeader = $request->header('Authorization');
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        try {
            $payload  = JWTAuth::setRequest($request)->getPayload();
            $tenantId = $payload->get('tid');

            if (empty($tenantId)) {
                return null;
            }

            return $this->tenantRepository->findById((int) $tenantId);
        } catch (\Throwable) {
            // Token expired or invalid — proceed to next detection method
            return null;
        }
    }

    /**
     * Detect tenant from subdomain.
     * e.g., library1.kutubxona.uz → slug = "library1"
     */
    private function fromSubdomain(Request $request): ?Tenant
    {
        $host       = $request->getHost();
        $baseDomain = config('app.base_domain', 'kutubxona.uz');

        if (!str_ends_with($host, '.' . $baseDomain)) {
            return null;
        }

        $slug = str_replace('.' . $baseDomain, '', $host);

        if (empty($slug) || $slug === $baseDomain || $slug === 'www') {
            return null;
        }

        return $this->tenantRepository->findBySlug($slug);
    }

    /**
     * Detect tenant from custom domain.
     * e.g., library.school.edu → lookup in tenant_domains table
     */
    private function fromCustomDomain(Request $request): ?Tenant
    {
        $host = $request->getHost();

        // Skip if it's the base domain itself
        $baseDomain = config('app.base_domain', 'kutubxona.uz');
        if ($host === $baseDomain || $host === 'www.' . $baseDomain) {
            return null;
        }

        return $this->tenantRepository->findByDomain($host);
    }

    // ─── Tenant Status Checks ─────────────────────────────────────────────────────

    private function assertTenantAccessible(Tenant $tenant): ?JsonResponse
    {
        return match ($tenant->status) {
            TenantStatus::Suspended => $this->jsonError(
                'Your account has been suspended. Please contact support.',
                403,
                'TENANT_SUSPENDED'
            ),
            TenantStatus::Pending => $this->jsonError(
                'Your account is pending activation.',
                403,
                'TENANT_PENDING'
            ),
            TenantStatus::Cancelled => $this->jsonError(
                'This account has been cancelled.',
                410,
                'TENANT_CANCELLED'
            ),
            TenantStatus::Active => null, // Proceed
            default => $this->jsonError('Account status unknown.', 403, 'TENANT_UNKNOWN_STATUS'),
        };
    }

    // ─── Database Connection Switching ───────────────────────────────────────────

    private function switchDatabaseConnection(Tenant $tenant): void
    {
        $config = $tenant->getDatabaseConfig();
        if (!$config) return;

        try {
            config([
                'database.connections.tenant_dedicated' => [
                    'driver'    => 'mysql',
                    'host'      => $config['host'],
                    'port'      => $config['port'] ?? 3306,
                    'database'  => $config['database'],
                    'username'  => $config['username'],
                    'password'  => decrypt($config['password']),
                    'charset'   => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix'    => '',
                    'strict'    => true,
                ],
            ]);

            \Illuminate\Support\Facades\DB::purge('tenant_dedicated');
            \Illuminate\Support\Facades\DB::setDefaultConnection('tenant_dedicated');

            Log::info('Switched to dedicated DB', ['tenant_id' => $tenant->id]);
        } catch (\Throwable $e) {
            Log::error('Failed to switch to dedicated DB', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
            // Fall back to shared DB — don't fail the request
        }
    }

    // ─── Response helpers ─────────────────────────────────────────────────────────

    private function tenantNotFound(): JsonResponse
    {
        return $this->jsonError('Tenant not found.', 404, 'TENANT_NOT_FOUND');
    }

    private function jsonError(string $message, int $status, string $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'code'    => $code,
            'meta'    => [
                'timestamp'  => now()->toIso8601String(),
                'request_id' => request()->header('X-Request-ID', (string) \Illuminate\Support\Str::uuid()),
            ],
        ], $status);
    }
}
