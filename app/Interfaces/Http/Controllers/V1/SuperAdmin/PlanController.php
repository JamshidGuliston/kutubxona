<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\SuperAdmin;

use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(name="Super Admin - Plans", description="Subscription plan management")
 */
final class PlanController extends BaseController
{
    public function index(): JsonResponse
    {
        $plans = DB::table('plans')->orderBy('sort_order')->get();

        return $this->successResponse(
            data: $plans,
            message: 'Plans retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'slug'          => ['required', 'string', 'max:100', 'unique:plans,slug'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly'  => ['nullable', 'numeric', 'min:0'],
            'max_users'     => ['required', 'integer', 'min:1'],
            'max_books'     => ['required', 'integer', 'min:1'],
            'storage_quota' => ['required', 'integer', 'min:1'],
            'features'      => ['nullable', 'array'],
            'is_active'     => ['nullable', 'boolean'],
            'sort_order'    => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($validated['features'])) {
            $validated['features'] = json_encode($validated['features']);
        }

        $id = DB::table('plans')->insertGetId(array_merge($validated, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $this->successResponse(
            data: DB::table('plans')->find($id),
            message: 'Plan created successfully',
            status: 201
        );
    }

    public function show(int $plan): JsonResponse
    {
        $planData = DB::table('plans')->find($plan);

        if (!$planData) {
            return $this->errorResponse('Plan not found.', 404);
        }

        return $this->successResponse(data: $planData, message: 'Plan retrieved');
    }

    public function update(Request $request, int $plan): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100'],
            'price_monthly' => ['sometimes', 'numeric', 'min:0'],
            'price_yearly'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_users'     => ['sometimes', 'integer', 'min:1'],
            'max_books'     => ['sometimes', 'integer', 'min:1'],
            'storage_quota' => ['sometimes', 'integer', 'min:1'],
            'features'      => ['sometimes', 'nullable', 'array'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['features'])) {
            $validated['features'] = json_encode($validated['features']);
        }

        DB::table('plans')->where('id', $plan)->update(array_merge($validated, ['updated_at' => now()]));

        return $this->successResponse(
            data: DB::table('plans')->find($plan),
            message: 'Plan updated'
        );
    }

    public function destroy(int $plan): JsonResponse
    {
        $tenantCount = DB::table('tenants')->where('plan_id', $plan)->count();

        if ($tenantCount > 0) {
            return $this->errorResponse('Cannot delete plan with active tenants.', 422);
        }

        DB::table('plans')->where('id', $plan)->delete();

        return $this->successResponse(data: null, message: 'Plan deleted');
    }
}
