<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\V1\Admin\TenantAdminController;
use App\Interfaces\Http\Controllers\V1\AudioBook\AudioBookController;
use App\Interfaces\Http\Controllers\V1\Auth\AuthController;
use App\Interfaces\Http\Controllers\V1\Book\BookController;
use App\Interfaces\Http\Controllers\V1\Reading\ReadingProgressController;
use App\Interfaces\Http\Controllers\V1\SuperAdmin\TenantController as SuperAdminTenantController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes — Kutubxona.uz Multi-Tenant Digital Library
|--------------------------------------------------------------------------
|
| Middleware stack:
|   - 'tenant'        → TenantMiddleware (resolves and binds current tenant)
|   - 'tenant.scope'  → TenantScopeMiddleware (sets Eloquent scope + Spatie team)
|   - 'auth:api'      → JWT authentication (tymon/jwt-auth)
|   - 'throttle:api'  → Rate limiting (120/min for authenticated, 30/min guest)
|
*/

// Health check (no tenant middleware required)
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'checks' => [
            'database' => \Illuminate\Support\Facades\DB::connection()->getPdo() ? 'ok' : 'error',
            'redis'    => function () {
                try {
                    \Illuminate\Support\Facades\Cache::store('redis')->get('health_check');
                    return 'ok';
                } catch (\Throwable) {
                    return 'error';
                }
            },
        ],
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Versioned API Routes — All require tenant resolution
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['tenant', 'tenant.scope'])->group(function (): void {

    // ─── Authentication ───────────────────────────────────────────────────────
    Route::prefix('auth')->group(function (): void {
        // Public auth routes (rate limited strictly)
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
            Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
        });

        Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->name('auth.verify-email');

        Route::middleware('throttle:forgot-password')->group(function (): void {
            Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password');
        });
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');

        // Protected auth routes
        Route::middleware('auth:api')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        });
    });

    // ─── Public Routes (no auth required, tenant context set) ────────────────
    Route::middleware('throttle:api')->group(function (): void {

        // Books — public catalog
        Route::prefix('books')->group(function (): void {
            Route::get('/popular', [BookController::class, 'popular'])->name('books.popular');
            Route::get('/featured', [BookController::class, 'featured'])->name('books.featured');
            Route::get('/new-arrivals', [BookController::class, 'newArrivals'])->name('books.new-arrivals');
            Route::get('/', [BookController::class, 'index'])->name('books.index');
            Route::get('/{book}', [BookController::class, 'show'])->name('books.show');
        });

        // Audiobooks — public catalog
        Route::prefix('audiobooks')->group(function (): void {
            Route::get('/', [AudioBookController::class, 'index'])->name('audiobooks.index');
            Route::get('/{audiobook}', [AudioBookController::class, 'show'])->name('audiobooks.show');
        });

        // Search
        Route::prefix('search')->group(function (): void {
            Route::get('/', [\App\Interfaces\Http\Controllers\V1\Search\SearchController::class, 'search'])
                 ->name('search.query');
            Route::get('/autocomplete', [\App\Interfaces\Http\Controllers\V1\Search\SearchController::class, 'autocomplete'])
                 ->middleware('throttle:60,1')
                 ->name('search.autocomplete');
        });

        // Authors, Publishers, Categories, Tags — public
        Route::apiResource('authors', \App\Interfaces\Http\Controllers\V1\Author\AuthorController::class)
             ->only(['index', 'show']);
        Route::get('authors/{author}/books', [\App\Interfaces\Http\Controllers\V1\Author\AuthorController::class, 'books'])
             ->name('authors.books');

        Route::apiResource('publishers', \App\Interfaces\Http\Controllers\V1\Publisher\PublisherController::class)
             ->only(['index', 'show']);
        Route::get('publishers/{publisher}/books', [\App\Interfaces\Http\Controllers\V1\Publisher\PublisherController::class, 'books'])
             ->name('publishers.books');

        Route::apiResource('categories', \App\Interfaces\Http\Controllers\V1\Category\CategoryController::class)
             ->only(['index', 'show']);
        Route::get('categories/{category}/books', [\App\Interfaces\Http\Controllers\V1\Category\CategoryController::class, 'books'])
             ->name('categories.books');

        Route::get('tags', [\App\Interfaces\Http\Controllers\V1\Tag\TagController::class, 'index'])
             ->name('tags.index');
        Route::get('tags/cloud', [\App\Interfaces\Http\Controllers\V1\Tag\TagController::class, 'cloud'])
             ->name('tags.cloud');
    });

    // ─── Authenticated Routes ─────────────────────────────────────────────────
    Route::middleware(['auth:api', 'throttle:api'])->group(function (): void {

        // Books (write operations + download)
        Route::prefix('books')->group(function (): void {
            Route::post('/', [BookController::class, 'store'])->name('books.store');
            Route::put('/{book}', [BookController::class, 'update'])->name('books.update');
            Route::delete('/{book}', [BookController::class, 'destroy'])->name('books.destroy');

            Route::get('/{book}/download', [BookController::class, 'download'])
                 ->middleware('throttle:downloads')
                 ->name('books.download');
            Route::get('/{book}/stream', [BookController::class, 'stream'])->name('books.stream');

            // Reviews
            Route::get('/{book}/reviews', [\App\Interfaces\Http\Controllers\V1\Review\ReviewController::class, 'index'])
                 ->withoutMiddleware('auth:api'); // Public reviews
            Route::post('/{book}/reviews', [\App\Interfaces\Http\Controllers\V1\Review\ReviewController::class, 'store'])
                 ->name('books.reviews.store');
            Route::put('/{book}/reviews/{review}', [\App\Interfaces\Http\Controllers\V1\Review\ReviewController::class, 'update'])
                 ->name('books.reviews.update');
            Route::delete('/{book}/reviews/{review}', [\App\Interfaces\Http\Controllers\V1\Review\ReviewController::class, 'destroy'])
                 ->name('books.reviews.destroy');

            // Bookmarks & Highlights
            Route::get('/{bookId}/bookmarks', [ReadingProgressController::class, 'listBookmarks'])
                 ->name('books.bookmarks.index');
            Route::post('/{bookId}/bookmarks', [ReadingProgressController::class, 'createBookmark'])
                 ->name('books.bookmarks.store');
            Route::put('/{bookId}/bookmarks/{bookmark}', [ReadingProgressController::class, 'updateBookmark'])
                 ->name('books.bookmarks.update');
            Route::delete('/{bookId}/bookmarks/{bookmark}', [ReadingProgressController::class, 'deleteBookmark'])
                 ->name('books.bookmarks.destroy');

            Route::get('/{bookId}/highlights', [ReadingProgressController::class, 'listHighlights'])
                 ->name('books.highlights.index');
            Route::post('/{bookId}/highlights', [ReadingProgressController::class, 'createHighlight'])
                 ->name('books.highlights.store');
            Route::put('/{bookId}/highlights/{highlight}', [ReadingProgressController::class, 'updateHighlight'])
                 ->name('books.highlights.update');
            Route::delete('/{bookId}/highlights/{highlight}', [ReadingProgressController::class, 'deleteHighlight'])
                 ->name('books.highlights.destroy');
        });

        // Audiobooks (write operations + streaming)
        Route::prefix('audiobooks')->group(function (): void {
            Route::post('/', [AudioBookController::class, 'store'])->name('audiobooks.store');
            Route::put('/{audiobook}', [AudioBookController::class, 'update'])->name('audiobooks.update');
            Route::delete('/{audiobook}', [AudioBookController::class, 'destroy'])->name('audiobooks.destroy');
            Route::post('/{audiobook}/chapters', [AudioBookController::class, 'addChapter'])
                 ->name('audiobooks.chapters.store');
            Route::get('/{audiobook}/chapters/{chapter}/stream', [AudioBookController::class, 'streamChapter'])
                 ->name('audiobooks.chapters.stream');
            Route::delete('/{audiobook}/chapters/{chapter}', [AudioBookController::class, 'deleteChapter'])
                 ->name('audiobooks.chapters.destroy');
        });

        // Content management (admin/manager)
        Route::prefix('authors')->group(function (): void {
            Route::post('/', [\App\Interfaces\Http\Controllers\V1\Author\AuthorController::class, 'store'])
                 ->name('authors.store');
            Route::put('/{author}', [\App\Interfaces\Http\Controllers\V1\Author\AuthorController::class, 'update'])
                 ->name('authors.update');
            Route::delete('/{author}', [\App\Interfaces\Http\Controllers\V1\Author\AuthorController::class, 'destroy'])
                 ->name('authors.destroy');
        });

        Route::prefix('publishers')->group(function (): void {
            Route::post('/', [\App\Interfaces\Http\Controllers\V1\Publisher\PublisherController::class, 'store']);
            Route::put('/{publisher}', [\App\Interfaces\Http\Controllers\V1\Publisher\PublisherController::class, 'update']);
            Route::delete('/{publisher}', [\App\Interfaces\Http\Controllers\V1\Publisher\PublisherController::class, 'destroy']);
        });

        Route::prefix('categories')->group(function (): void {
            Route::post('/', [\App\Interfaces\Http\Controllers\V1\Category\CategoryController::class, 'store']);
            Route::put('/{category}', [\App\Interfaces\Http\Controllers\V1\Category\CategoryController::class, 'update']);
            Route::delete('/{category}', [\App\Interfaces\Http\Controllers\V1\Category\CategoryController::class, 'destroy']);
        });

        Route::prefix('tags')->group(function (): void {
            Route::post('/', [\App\Interfaces\Http\Controllers\V1\Tag\TagController::class, 'store']);
            Route::put('/{tag}', [\App\Interfaces\Http\Controllers\V1\Tag\TagController::class, 'update']);
            Route::delete('/{tag}', [\App\Interfaces\Http\Controllers\V1\Tag\TagController::class, 'destroy']);
        });

        // Reading progress
        Route::prefix('reading')->group(function (): void {
            Route::get('/progress', [ReadingProgressController::class, 'index'])->name('reading.progress');
            Route::put('/progress/{bookId}', [ReadingProgressController::class, 'updateBookProgress'])
                 ->name('reading.progress.update');
            Route::put('/audio-progress/{audiobookId}', [ReadingProgressController::class, 'updateAudioProgress'])
                 ->name('reading.audio-progress.update');
            Route::get('/history', [ReadingProgressController::class, 'history'])->name('reading.history');
        });

        // User profile & favorites
        Route::prefix('user')->group(function (): void {
            Route::get('/profile', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'profile'])
                 ->name('user.profile');
            Route::put('/profile', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'updateProfile'])
                 ->name('user.profile.update');
            Route::put('/password', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'changePassword'])
                 ->name('user.password');
            Route::delete('/account', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'deleteAccount'])
                 ->name('user.account.delete');

            Route::get('/favorites', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'favorites'])
                 ->name('user.favorites');
            Route::post('/favorites', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'addFavorite'])
                 ->name('user.favorites.add');
            Route::delete('/favorites/{type}/{id}', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'removeFavorite'])
                 ->name('user.favorites.remove');

            Route::get('/bookshelf', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'bookshelf'])
                 ->name('user.bookshelf');
            Route::get('/downloads', [\App\Interfaces\Http\Controllers\V1\User\UserController::class, 'downloads'])
                 ->name('user.downloads');
        });

        // ─── Tenant Admin Panel ───────────────────────────────────────────────
        Route::prefix('admin')
             ->middleware('role:tenant_admin|tenant_manager|super_admin')
             ->group(function (): void {
                 Route::get('/dashboard', [TenantAdminController::class, 'dashboard'])
                      ->name('admin.dashboard');

                 // User management
                 Route::get('/users', [TenantAdminController::class, 'listUsers'])->name('admin.users.index');
                 Route::get('/users/{user}', [TenantAdminController::class, 'showUser'])->name('admin.users.show');
                 Route::post('/users', [TenantAdminController::class, 'createUser'])->name('admin.users.store');
                 Route::put('/users/{user}', [TenantAdminController::class, 'updateUser'])->name('admin.users.update');
                 Route::put('/users/{user}/status', [TenantAdminController::class, 'updateUserStatus'])
                      ->name('admin.users.status');
                 Route::put('/users/{user}/role', [TenantAdminController::class, 'updateUserRole'])
                      ->name('admin.users.role');
                 Route::delete('/users/{user}', [TenantAdminController::class, 'deleteUser'])
                      ->name('admin.users.destroy')
                      ->middleware('role:tenant_admin|super_admin');

                 // Content management
                 Route::put('/books/{book}/publish', [TenantAdminController::class, 'publishBook'])
                      ->name('admin.books.publish');
                 Route::put('/books/{book}/archive', [TenantAdminController::class, 'archiveBook'])
                      ->name('admin.books.archive');
                 Route::get('/books', [BookController::class, 'index'])->name('admin.books.index'); // shows all statuses

                 // Reviews
                 Route::get('/reviews', [TenantAdminController::class, 'listReviews'])->name('admin.reviews.index');
                 Route::put('/reviews/{review}/approve', [TenantAdminController::class, 'approveReview'])
                      ->name('admin.reviews.approve');
                 Route::delete('/reviews/{review}', [TenantAdminController::class, 'deleteReview'])
                      ->name('admin.reviews.destroy');

                 // Analytics
                 Route::get('/analytics/overview', [TenantAdminController::class, 'analyticsOverview'])
                      ->name('admin.analytics.overview');
             });
    });

    // ─── Super Admin Routes ───────────────────────────────────────────────────
    Route::prefix('super-admin')
         ->middleware(['auth:api', 'role:super_admin', 'throttle:super-admin'])
         ->group(function (): void {
             // Tenant management
             Route::get('/tenants', [SuperAdminTenantController::class, 'index'])->name('super-admin.tenants.index');
             Route::post('/tenants', [SuperAdminTenantController::class, 'store'])->name('super-admin.tenants.store');
             Route::get('/tenants/{tenant}', [SuperAdminTenantController::class, 'show'])->name('super-admin.tenants.show');
             Route::put('/tenants/{tenant}', [SuperAdminTenantController::class, 'update'])->name('super-admin.tenants.update');
             Route::delete('/tenants/{tenant}', [SuperAdminTenantController::class, 'destroy'])->name('super-admin.tenants.destroy');
             Route::post('/tenants/{tenant}/suspend', [SuperAdminTenantController::class, 'suspend'])
                  ->name('super-admin.tenants.suspend');
             Route::post('/tenants/{tenant}/activate', [SuperAdminTenantController::class, 'activate'])
                  ->name('super-admin.tenants.activate');
             Route::get('/tenants/{tenant}/stats', [SuperAdminTenantController::class, 'stats'])
                  ->name('super-admin.tenants.stats');

             // Platform analytics
             Route::get('/analytics/platform', [SuperAdminTenantController::class, 'platformAnalytics'])
                  ->name('super-admin.analytics.platform');

             // Plan management
             Route::apiResource('plans', \App\Interfaces\Http\Controllers\V1\SuperAdmin\PlanController::class)
                  ->names('super-admin.plans');

             // System
             Route::get('/system/health', function () {
                 return response()->json([
                     'database' => 'ok',
                     'redis'    => 'ok',
                     'queue'    => 'ok',
                     'storage'  => 'ok',
                 ]);
             })->name('super-admin.system.health');
         });
});

/*
|--------------------------------------------------------------------------
| Rate Limiter Configuration
| Defined in App\Providers\RouteServiceProvider
|--------------------------------------------------------------------------
|
| 'api'            → 120/min per user or IP (authenticated/guest)
| 'auth'           → 5/min per IP for login/register
| 'forgot-password'→ 3/15min per IP
| 'downloads'      → 10/hour per user
| 'super-admin'    → 300/min per user
|
*/
