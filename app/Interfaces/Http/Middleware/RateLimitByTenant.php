<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitByTenant
{
    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->has('tenant') ? app('tenant') : null;

        if (!$tenant) {
            return $next($request);
        }

        $key      = 'tenant:' . $tenant->id . ':' . $request->ip();
        $maxPerMin = 120;

        if ($this->limiter->tooManyAttempts($key, $maxPerMin)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Too many requests. Please slow down.',
                'code'    => 'RATE_LIMITED',
            ], 429);
        }

        $this->limiter->hit($key, 60);

        return $next($request);
    }
}
