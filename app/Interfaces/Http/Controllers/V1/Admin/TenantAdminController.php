<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Admin;

use App\Application\Services\BookService;
use App\Application\Services\TenantService;
use App\Infrastructure\Repositories\UserRepository;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Resources\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @OA\Tag(name="Admin", description="Tenant admin panel endpoints")
 */
final class TenantAdminController extends BaseController
{
    public function __construct(
        private readonly TenantService  $tenantService,
        private readonly UserRepository $userRepository,
        private readonly BookService    $bookService,
    ) {}

    /**
     * @OA\Get(
     *   path="/api/v1/admin/dashboard",
     *   tags={"Admin"},
     *   summary="Get admin dashboard statistics",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Dashboard data")
     * )
     */
    public function dashboard(): JsonResponse
    {
        $tenant = app('tenant');
        $stats  = $this->tenantService->getTenantStats($tenant);

        // Recent books
        $recentBooks = \App\Domain\Book\Models\Book::with(['author'])
            ->latest()
            ->limit(5)
            ->get();

        // Recently registered users
        $recentUsers = \App\Domain\User\Models\User::latest()
            ->limit(5)
            ->get(['id', 'name', 'email', 'created_at', 'status']);

        // Popular books this month
        $popularBooks = $this->bookService->getPopularBooks(10);

        return $this->successResponse(
            data: [
                'stats'        => $stats,
                'recent_books' => $recentBooks,
                'recent_users' => $recentUsers,
                'popular_books'=> $popularBooks,
            ],
            message: 'Dashboard data retrieved'
        );
    }

    // ─── User Management ─────────────────────────────────────────────────────────

    /**
     * @OA\Get(
     *   path="/api/v1/admin/users",
     *   tags={"Admin"},
     *   summary="List users",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="User list")
     * )
     */
    public function listUsers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Domain\User\Models\User::class);

        $filters = $request->only(['search', 'status', 'role']);
        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);
        $tenant  = app('tenant');

        $paginator = $this->userRepository->paginate($tenant->id, $filters, $page, $perPage);

        return $this->paginatedResponse(
            data: UserResource::collection($paginator->items()),
            paginator: $paginator,
            message: 'Users retrieved'
        );
    }

    /**
     * @OA\Get(path="/api/v1/admin/users/{id}", tags={"Admin"}, summary="Get user detail", security={{"bearerAuth":{}}})
     */
    public function showUser(\App\Domain\User\Models\User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return $this->successResponse(
            data: new UserResource($user->load(['roles', 'readingProgress'])),
            message: 'User retrieved'
        );
    }

    /**
     * @OA\Post(path="/api/v1/admin/users", tags={"Admin"}, summary="Create user", security={{"bearerAuth":{}}})
     */
    public function createUser(Request $request): JsonResponse
    {
        $this->authorize('create', \App\Domain\User\Models\User::class);

        $tenant   = app('tenant');
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', 'string', 'in:tenant_manager,user'],
            'locale'   => ['nullable', 'string', 'in:uz,ru,en'],
        ]);

        // Check email uniqueness in tenant
        if ($this->userRepository->existsByEmail($validated['email'], $tenant->id)) {
            return $this->errorResponse('Email already exists in this organization.', 409);
        }

        $user = \App\Domain\User\Models\User::create([
            'tenant_id'         => $tenant->id,
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => Hash::make($validated['password']),
            'locale'            => $validated['locale'] ?? 'uz',
            'email_verified_at' => now(),
            'status'            => 'active',
        ]);

        setPermissionsTeamId($tenant->id);
        $user->assignRole($validated['role']);

        return $this->successResponse(
            data: new UserResource($user->load('roles')),
            message: 'User created',
            status: 201
        );
    }

    /**
     * @OA\Put(path="/api/v1/admin/users/{id}", tags={"Admin"}, summary="Update user", security={{"bearerAuth":{}}})
     */
    public function updateUser(Request $request, \App\Domain\User\Models\User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'in:uz,ru,en'],
        ]);

        $user->update($validated);

        return $this->successResponse(
            data: new UserResource($user->fresh('roles')),
            message: 'User updated'
        );
    }

    /**
     * @OA\Put(
     *   path="/api/v1/admin/users/{id}/status",
     *   tags={"Admin"},
     *   summary="Update user status",
     *   security={{"bearerAuth":{}}}
     * )
     */
    public function updateUserStatus(Request $request, \App\Domain\User\Models\User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive,banned'],
        ]);

        $user->update(['status' => $validated['status']]);

        return $this->successResponse(
            data: ['status' => $user->status],
            message: "User status updated to {$validated['status']}"
        );
    }

    /**
     * @OA\Put(
     *   path="/api/v1/admin/users/{id}/role",
     *   tags={"Admin"},
     *   summary="Assign role to user",
     *   security={{"bearerAuth":{}}}
     * )
     */
    public function updateUserRole(Request $request, \App\Domain\User\Models\User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:tenant_manager,user'],
        ]);

        $tenant = app('tenant');
        setPermissionsTeamId($tenant->id);
        $user->syncRoles([$validated['role']]);

        return $this->successResponse(
            data: new UserResource($user->fresh('roles')),
            message: "Role updated to {$validated['role']}"
        );
    }

    /**
     * @OA\Delete(path="/api/v1/admin/users/{id}", tags={"Admin"}, summary="Delete user", security={{"bearerAuth":{}}})
     */
    public function deleteUser(\App\Domain\User\Models\User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        // Prevent self-deletion
        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot delete your own account from here.', 403);
        }

        $user->delete();

        return $this->successResponse(data: null, message: 'User deleted');
    }

    // ─── Analytics ───────────────────────────────────────────────────────────────

    /**
     * @OA\Get(path="/api/v1/admin/analytics/overview", tags={"Admin"}, summary="Analytics overview", security={{"bearerAuth":{}}})
     */
    public function analyticsOverview(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $period = $request->query('period', '30d');

        $startDate = match ($period) {
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y'  => now()->subYear(),
            default => now()->subDays(30),
        };

        $analytics = DB::table('analytics_events')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->pluck('count', 'event_type');

        return $this->successResponse(
            data: [
                'period'     => $period,
                'start_date' => $startDate->toIso8601String(),
                'events'     => $analytics,
                'stats'      => $this->tenantService->getTenantStats($tenant),
            ],
            message: 'Analytics retrieved'
        );
    }

    // ─── Content Management ───────────────────────────────────────────────────────

    /**
     * @OA\Put(path="/api/v1/admin/books/{id}/publish", tags={"Admin"}, summary="Publish a book", security={{"bearerAuth":{}}})
     */
    public function publishBook(\App\Domain\Book\Models\Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $this->bookService->publishBook($book);

        return $this->successResponse(data: null, message: 'Book published');
    }

    /**
     * @OA\Put(path="/api/v1/admin/books/{id}/archive", tags={"Admin"}, summary="Archive a book", security={{"bearerAuth":{}}})
     */
    public function archiveBook(\App\Domain\Book\Models\Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $this->bookService->archiveBook($book);

        return $this->successResponse(data: null, message: 'Book archived');
    }

    /**
     * @OA\Get(path="/api/v1/admin/reviews", tags={"Admin"}, summary="List reviews pending approval", security={{"bearerAuth":{}}})
     */
    public function listReviews(Request $request): JsonResponse
    {
        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);

        $reviews = \App\Domain\Reading\Models\Review::with(['user', 'book'])
            ->where('is_approved', false)
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginatedResponse(
            data: $reviews->items(),
            paginator: $reviews,
            message: 'Pending reviews retrieved'
        );
    }

    /**
     * @OA\Put(path="/api/v1/admin/reviews/{id}/approve", tags={"Admin"}, summary="Approve a review", security={{"bearerAuth":{}}})
     */
    public function approveReview(\App\Domain\Reading\Models\Review $review): JsonResponse
    {
        $review->update([
            'is_approved' => true,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Recalculate book rating
        if ($review->book_id) {
            $review->book->recalculateRating();
        }

        return $this->successResponse(data: null, message: 'Review approved');
    }

    /**
     * @OA\Delete(path="/api/v1/admin/reviews/{id}", tags={"Admin"}, summary="Delete a review", security={{"bearerAuth":{}}})
     */
    public function deleteReview(\App\Domain\Reading\Models\Review $review): JsonResponse
    {
        $review->delete();

        return $this->successResponse(data: null, message: 'Review deleted');
    }
}
