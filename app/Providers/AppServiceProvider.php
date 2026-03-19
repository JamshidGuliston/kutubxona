<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Services\AnalyticsService;
use App\Application\Services\AudioBookService;
use App\Application\Services\AuthService;
use App\Application\Services\BookService;
use App\Application\Services\ReadingService;
use App\Application\Services\SearchService;
use App\Application\Services\StorageService;
use App\Application\Services\TenantService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * All singleton service bindings.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AnalyticsService::class => AnalyticsService::class,
        StorageService::class   => StorageService::class,
    ];

    public function register(): void
    {
        // ── Service bindings ─────────────────────────────────────────────────
        $this->app->bind(AuthService::class, AuthService::class);
        $this->app->bind(BookService::class, BookService::class);
        $this->app->bind(TenantService::class, TenantService::class);
        $this->app->bind(ReadingService::class, ReadingService::class);
        $this->app->bind(SearchService::class, SearchService::class);
        $this->app->bind(AudioBookService::class, AudioBookService::class);

        // ── Telescope (dev only) ─────────────────────────────────────────────
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        // ── Eloquent strict mode ─────────────────────────────────────────────
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);

        // ── Morph map for polymorphic relations ──────────────────────────────
        Relation::enforceMorphMap([
            'book'             => \App\Domain\Book\Models\Book::class,
            'audiobook'        => \App\Domain\AudioBook\Models\AudioBook::class,
            'user'             => \App\Domain\User\Models\User::class,
            'tenant'           => \App\Domain\Tenant\Models\Tenant::class,
            'category'         => \App\Domain\Library\Models\Category::class,
            'author'           => \App\Domain\Library\Models\Author::class,
            'publisher'        => \App\Domain\Library\Models\Publisher::class,
            'review'           => \App\Domain\Library\Models\Review::class,
        ]);

        // ── Force HTTPS in production ────────────────────────────────────────
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // ── Rate limiters ────────────────────────────────────────────────────
        $this->configureRateLimiting();

        // ── Collection macros ────────────────────────────────────────────────
        $this->registerCollectionMacros();

        // ── DB query log in debug mode ───────────────────────────────────────
        if ($this->app->hasDebugModeEnabled() && ! $this->app->isProduction()) {
            DB::listen(function ($query): void {
                if ($query->time > 1000) {
                    logger()->warning('Slow query detected', [
                        'sql'      => $query->sql,
                        'bindings' => $query->bindings,
                        'time_ms'  => $query->time,
                    ]);
                }
            });
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by(
                Str::lower($request->input('email', '')) . '|' . $request->ip()
            );
        });

        RateLimiter::for('search', function (Request $request): Limit {
            return Limit::perMinute(30)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }

    private function registerCollectionMacros(): void
    {
        Collection::macro('toSelectOptions', function (string $labelKey = 'name', string $valueKey = 'id'): Collection {
            /** @var Collection $this */
            return $this->map(fn ($item) => [
                'value' => data_get($item, $valueKey),
                'label' => data_get($item, $labelKey),
            ]);
        });

        Collection::macro('groupByKey', function (string $key): Collection {
            /** @var Collection $this */
            return $this->groupBy($key)->map->values();
        });
    }
}
