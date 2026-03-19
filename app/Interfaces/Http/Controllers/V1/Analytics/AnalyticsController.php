<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Analytics;

use App\Application\Services\AnalyticsService;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Analytics", description="Tenant analytics and reporting")
 */
final class AnalyticsController extends BaseController
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
    ) {}

    /**
     * GET /api/v1/admin/analytics/overview
     *
     * Returns tenant dashboard analytics.
     */
    public function tenantOverview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'in:7d,30d,90d,365d'],
        ]);

        $tenant = app('tenant');
        $data   = $this->analyticsService->getTenantOverview(
            $tenant->id,
            $validated['period'] ?? '30d'
        );

        return $this->successResponse(
            data: $data,
            message: 'Analytics overview retrieved'
        );
    }

    /**
     * GET /api/v1/super-admin/analytics/platform
     *
     * Platform-wide analytics for super admins only.
     */
    public function platformOverview(): JsonResponse
    {
        $data = $this->analyticsService->getPlatformOverview();

        return $this->successResponse(
            data: $data,
            message: 'Platform analytics retrieved'
        );
    }
}
