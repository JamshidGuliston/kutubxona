<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contracts\Repositories\BookRepositoryInterface;
use App\Domain\Contracts\Repositories\ReadingProgressRepositoryInterface;
use App\Domain\Contracts\Repositories\TenantRepositoryInterface;
use App\Domain\Contracts\Repositories\UserRepositoryInterface;
use App\Infrastructure\Repositories\BookRepository;
use App\Infrastructure\Repositories\ReadingProgressRepository;
use App\Infrastructure\Repositories\TenantRepository;
use App\Infrastructure\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds all repository interfaces to their concrete implementations.
 *
 * Keeping this in a dedicated provider keeps AppServiceProvider clean
 * and makes it trivial to swap implementations (e.g., for testing or
 * for switching from Eloquent to a different persistence layer).
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * All interface → implementation bindings.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        BookRepositoryInterface::class            => BookRepository::class,
        TenantRepositoryInterface::class          => TenantRepository::class,
        UserRepositoryInterface::class            => UserRepository::class,
        ReadingProgressRepositoryInterface::class => ReadingProgressRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }

    public function boot(): void
    {
        // No boot logic required for repository bindings
    }
}
