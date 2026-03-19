<?php

declare(strict_types=1);

use App\Exceptions\Handler;
use App\Interfaces\Http\Middleware\ApiVersionMiddleware;
use App\Interfaces\Http\Middleware\JsonResponseMiddleware;
use App\Interfaces\Http\Middleware\RateLimitByTenant;
use App\Interfaces\Http\Middleware\TenantMiddleware;
use App\Interfaces\Http\Middleware\TenantScopeMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ── Global API middleware stack ───────────────────────────────────────
        $middleware->api(prepend: [
            JsonResponseMiddleware::class,
            ApiVersionMiddleware::class,
        ]);

        // ── Named (alias) middleware ─────────────────────────────────────────
        $middleware->alias([
            'tenant'            => TenantMiddleware::class,
            'tenant.scope'      => TenantScopeMiddleware::class,
            'rate.tenant'       => RateLimitByTenant::class,
            'json'              => JsonResponseMiddleware::class,
            'api.version'       => ApiVersionMiddleware::class,
        ]);

        // ── Middleware groups ────────────────────────────────────────────────
        $middleware->appendToGroup('tenant-api', [
            TenantMiddleware::class,
            TenantScopeMiddleware::class,
            RateLimitByTenant::class,
        ]);

        // ── Trusted proxies (for load balancers / Cloudflare) ────────────────
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                   | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                   | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                   | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

    })
    ->withProviders([
        App\Providers\RepositoryServiceProvider::class,
        App\Providers\EventServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request): ?\Illuminate\Http\JsonResponse {
            if ($request->expectsJson() || $request->is('api/*')) {
                return app(Handler::class)->renderApiException($e);
            }
            return null;
        });

        // Report to Sentry in production
        $exceptions->reportable(function (\Throwable $e): void {
            if (app()->bound('sentry') && app()->isProduction()) {
                app('sentry')->captureException($e);
            }
        });
    })
    ->create();
