<?php

declare(strict_types=1);

namespace App\Application\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AnalyticsService
{
    /**
     * Get tenant analytics overview.
     */
    public function getTenantOverview(int $tenantId, string $period = '30d'): array
    {
        $days     = $this->parsePeriodDays($period);
        $cacheKey = "analytics:tenant:{$tenantId}:overview:{$period}";

        return Cache::remember($cacheKey, 600, function () use ($tenantId, $days): array {
            $since = now()->subDays($days);

            return [
                'total_books'      => DB::table('books')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
                'total_audiobooks' => DB::table('audiobooks')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
                'total_users'      => DB::table('users')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
                'active_users'     => DB::table('users')
                    ->where('tenant_id', $tenantId)
                    ->where('last_login_at', '>=', now()->subDays(30))->count(),
                'total_downloads'  => DB::table('analytics_events')
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', 'book_download')->count(),
                'recent_downloads' => DB::table('analytics_events')
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', 'book_download')
                    ->where('created_at', '>=', $since)->count(),
                'total_reads'      => DB::table('reading_progress')
                    ->where('tenant_id', $tenantId)->count(),
                'popular_books'    => $this->getPopularBooks($tenantId, 5),
                'reading_trends'   => $this->getReadingTrends($tenantId, $days),
                'storage_used_mb'  => round(
                    (float) DB::table('tenants')->where('id', $tenantId)->value('storage_used') / 1024 / 1024,
                    2
                ),
            ];
        });
    }

    /**
     * Get platform-wide analytics for super admin.
     */
    public function getPlatformOverview(): array
    {
        return Cache::remember('analytics:platform:overview', 300, function (): array {
            return [
                'total_tenants'   => DB::table('tenants')->whereNull('deleted_at')->count(),
                'active_tenants'  => DB::table('tenants')->where('status', 'active')->count(),
                'trial_tenants'   => DB::table('tenants')->where('trial_ends_at', '>', now())->count(),
                'total_users'     => DB::table('users')->whereNull('deleted_at')->count(),
                'total_books'     => DB::table('books')->whereNull('deleted_at')->count(),
                'total_audiobooks'=> DB::table('audiobooks')->whereNull('deleted_at')->count(),
                'total_downloads' => DB::table('analytics_events')
                    ->where('event_type', 'book_download')->count(),
                'new_tenants_30d' => DB::table('tenants')
                    ->where('created_at', '>=', now()->subDays(30))->count(),
                'new_users_30d'   => DB::table('users')
                    ->where('created_at', '>=', now()->subDays(30))->count(),
                'top_tenants'     => $this->getTopTenants(10),
            ];
        });
    }

    /**
     * Log an analytics event asynchronously.
     */
    public function logEvent(int $tenantId, string $eventType, array $payload = [], ?int $userId = null): void
    {
        DB::table('analytics_events')->insert([
            'tenant_id'  => $tenantId,
            'user_id'    => $userId,
            'event_type' => $eventType,
            'payload'    => json_encode($payload),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get top books for a tenant by download count.
     */
    private function getPopularBooks(int $tenantId, int $limit): array
    {
        return DB::table('books')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->orderByDesc('download_count')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'download_count', 'average_rating'])
            ->toArray();
    }

    /**
     * Get daily reading trends for last N days.
     */
    private function getReadingTrends(int $tenantId, int $days): array
    {
        return DB::table('reading_progress')
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '>=', now()->subDays($days))
            ->select(DB::raw('DATE(updated_at) as date'), DB::raw('COUNT(*) as reads'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Get top tenants by user count.
     */
    private function getTopTenants(int $limit): array
    {
        return DB::table('tenants')
            ->whereNull('deleted_at')
            ->select(
                'tenants.id',
                'tenants.name',
                'tenants.slug',
                'tenants.status',
                DB::raw('(SELECT COUNT(*) FROM users WHERE users.tenant_id = tenants.id AND users.deleted_at IS NULL) as user_count'),
                DB::raw('(SELECT COUNT(*) FROM books WHERE books.tenant_id = tenants.id AND books.deleted_at IS NULL) as book_count')
            )
            ->orderByDesc('user_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function parsePeriodDays(string $period): int
    {
        return match ($period) {
            '7d'    => 7,
            '30d'   => 30,
            '90d'   => 90,
            '365d'  => 365,
            default => 30,
        };
    }
}
