<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Domain\Localization\Models\TenantLanguage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveLocaleMiddleware
 *
 * Priority chain:
 *   1. ?lang= query parameter
 *   2. X-Locale request header
 *   3. locale cookie
 *   4. Accept-Language header
 *   5. tenant default_locale
 *   6. config('app.locale')
 *
 * Validates against current tenant's tenant_languages (is_active=true).
 * Silently falls back to tenant default if invalid.
 */
final class ResolveLocaleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->has('tenant') ? app('tenant') : null;
        $allowedLocales = $this->getAllowedLocales($tenant?->id);
        $defaultLocale = $tenant?->default_locale ?? config('app.locale', 'uz');

        $requested = $this->detectRequestedLocale($request, $defaultLocale);
        $locale = in_array($requested, $allowedLocales, true) ? $requested : $defaultLocale;

        app()->setLocale($locale);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        return $response;
    }

    private function detectRequestedLocale(Request $request, string $fallback): string
    {
        if ($lang = $request->query('lang')) return $this->normalize((string) $lang);
        if ($lang = $request->header('X-Locale')) return $this->normalize((string) $lang);
        if ($lang = $request->cookie('locale')) return $this->normalize((string) $lang);
        if ($lang = $this->parseAcceptLanguage($request)) return $this->normalize($lang);
        return $fallback;
    }

    private function parseAcceptLanguage(Request $request): ?string
    {
        $header = $request->header('Accept-Language');
        if (! $header) return null;
        $items = AcceptHeader::fromString($header)->all();
        if (empty($items)) return null;
        $first = array_shift($items);
        return explode('-', $first->getValue())[0] ?? null;
    }

    private function normalize(string $locale): string
    {
        return strtolower(trim($locale));
    }

    /**
     * @return list<string>
     */
    private function getAllowedLocales(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [config('app.locale', 'uz')];
        }
        return Cache::remember(
            "tenant.{$tenantId}.locales",
            300,
            fn () => TenantLanguage::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->pluck('code')
                ->all()
        );
    }
}
