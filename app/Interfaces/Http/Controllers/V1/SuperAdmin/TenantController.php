<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\SuperAdmin;

use App\Application\Services\TenantService;
use App\Domain\Tenant\Models\Tenant;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Resources\Tenant\TenantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="SuperAdmin - Tenants", description="Super admin tenant management")
 */
final class TenantController extends BaseController
{
    public function __construct(
        private readonly TenantService $tenantService,
    ) {}

    /**
     * @OA\Get(
     *   path="/api/v1/super-admin/tenants",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="List all tenants",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"active","suspended","pending","cancelled"})),
     *   @OA\Parameter(name="plan_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="Tenant list")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'plan_id']);
        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);

        $paginator = $this->tenantService->getAllTenants($filters, $page, $perPage);

        return $this->paginatedResponse(
            data: TenantResource::collection($paginator->items()),
            paginator: $paginator,
            message: 'Tenants retrieved'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/super-admin/tenants/{id}",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Get tenant detail",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true),
     *   @OA\Response(response=200, description="Tenant detail"),
     *   @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $stats = $this->tenantService->getTenantStats($tenant);

        return $this->successResponse(
            data: [
                'tenant' => new TenantResource($tenant->load(['domains', 'plan', 'subscription'])),
                'stats'  => $stats,
            ],
            message: 'Tenant retrieved'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/super-admin/tenants",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Create a new tenant",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","slug","admin_name","admin_email","admin_password"},
     *       @OA\Property(property="name", type="string"),
     *       @OA\Property(property="slug", type="string"),
     *       @OA\Property(property="plan_id", type="integer"),
     *       @OA\Property(property="admin_name", type="string"),
     *       @OA\Property(property="admin_email", type="string"),
     *       @OA\Property(property="admin_password", type="string"),
     *       @OA\Property(property="settings", type="object"),
     *       @OA\Property(property="custom_domain", type="string")
     *     )
     *   ),
     *   @OA\Response(response=201, description="Tenant created"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'slug'           => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', 'unique:tenants,slug'],
            'plan_id'        => ['nullable', 'integer', 'exists:plans,id'],
            'admin_name'     => ['required', 'string', 'max:255'],
            'admin_email'    => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
            'settings'       => ['nullable', 'array'],
            'custom_domain'  => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = $this->tenantService->createTenant($validated);

        return $this->successResponse(
            data: new TenantResource($tenant),
            message: 'Tenant created successfully',
            status: 201
        );
    }

    /**
     * @OA\Put(
     *   path="/api/v1/super-admin/tenants/{id}",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Update tenant",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true),
     *   @OA\Response(response=200, description="Tenant updated")
     * )
     */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'plan_id'  => ['sometimes', 'nullable', 'integer', 'exists:plans,id'],
            'settings' => ['sometimes', 'array'],
        ]);

        $updated = $this->tenantService->updateTenant($tenant, $validated);

        return $this->successResponse(
            data: new TenantResource($updated),
            message: 'Tenant updated'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/super-admin/tenants/{id}/suspend",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Suspend a tenant",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     @OA\JsonContent(@OA\Property(property="reason", type="string"))
     *   ),
     *   @OA\Response(response=200, description="Tenant suspended")
     * )
     */
    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $suspended = $this->tenantService->suspendTenant($tenant, $validated['reason'] ?? '');

        return $this->successResponse(
            data: new TenantResource($suspended),
            message: 'Tenant suspended'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/super-admin/tenants/{id}/activate",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Activate a suspended tenant",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Tenant activated")
     * )
     */
    public function activate(Tenant $tenant): JsonResponse
    {
        $activated = $this->tenantService->activateTenant($tenant);

        return $this->successResponse(
            data: new TenantResource($activated),
            message: 'Tenant activated'
        );
    }

    /**
     * @OA\Delete(
     *   path="/api/v1/super-admin/tenants/{id}",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Cancel tenant (soft delete, data retained 90 days)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Tenant cancelled"),
     *   @OA\Response(response=422, description="Cannot cancel active subscription")
     * )
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
        $this->tenantService->cancelTenant($tenant);

        return $this->successResponse(
            data: null,
            message: 'Tenant cancelled. Data will be retained for 90 days.'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/super-admin/tenants/{id}/stats",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Get detailed stats for a tenant",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Tenant stats")
     * )
     */
    public function stats(Tenant $tenant): JsonResponse
    {
        $stats = $this->tenantService->getTenantStats($tenant);

        return $this->successResponse(
            data: $stats,
            message: 'Tenant stats retrieved'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/super-admin/analytics/platform",
     *   tags={"SuperAdmin - Tenants"},
     *   summary="Platform-wide analytics",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Platform analytics")
     * )
     */
    public function platformAnalytics(): JsonResponse
    {
        $totalTenants = Tenant::withoutGlobalScopes()->count();
        $activeTenants = Tenant::withoutGlobalScopes()->where('status', 'active')->count();
        $totalUsers = \App\Domain\User\Models\User::withoutGlobalScopes()->count();
        $totalBooks = \App\Domain\Book\Models\Book::withoutGlobalScopes()->count();
        $totalDownloads = \App\Domain\Book\Models\Book::withoutGlobalScopes()->sum('download_count');

        return $this->successResponse(
            data: [
                'tenants' => [
                    'total'   => $totalTenants,
                    'active'  => $activeTenants,
                    'suspended' => Tenant::withoutGlobalScopes()->where('status', 'suspended')->count(),
                    'on_trial'  => Tenant::withoutGlobalScopes()->onTrial()->count(),
                ],
                'users'  => ['total' => $totalUsers],
                'content'=> [
                    'total_books'     => $totalBooks,
                    'total_downloads' => $totalDownloads,
                ],
            ],
            message: 'Platform analytics retrieved'
        );
    }
}
