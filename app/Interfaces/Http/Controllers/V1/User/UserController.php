<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\User;

use App\Application\Services\ReadingService;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Resources\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * @OA\Tag(name="User", description="User profile and personal library")
 */
final class UserController extends BaseController
{
    public function __construct(
        private readonly ReadingService $readingService,
    ) {}

    /**
     * GET /api/v1/user/profile
     */
    public function profile(): JsonResponse
    {
        $user = auth()->user()->load(['roles', 'tenant']);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'Profile retrieved successfully'
        );
    }

    /**
     * PUT /api/v1/user/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'   => ['sometimes', 'string', 'min:2', 'max:255'],
            'locale' => ['sometimes', 'string', 'in:uz,ru,en'],
            'avatar' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'bio'    => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $user = auth()->user();

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store(
                'tenants/' . $user->tenant_id . '/avatars',
                's3'
            );
            $validated['avatar'] = $path;
        }

        $user->update(array_filter($validated, fn ($v) => $v !== null));

        return $this->successResponse(
            data: new UserResource($user->fresh(['roles', 'tenant'])),
            message: 'Profile updated successfully'
        );
    }

    /**
     * PUT /api/v1/user/password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password'      => ['required', 'string'],
            'password'              => ['required', Password::min(8)->mixedCase()->numbers(), 'confirmed'],
            'password_confirmation' => ['required'],
        ]);

        $user = auth()->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->errorResponse('Current password is incorrect.', 422);
        }

        $user->update([
            'password'            => Hash::make($validated['password']),
            'password_changed_at' => now(),
        ]);

        return $this->successResponse(
            data: null,
            message: 'Password changed successfully'
        );
    }

    /**
     * DELETE /api/v1/user/account
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'reason'   => ['nullable', 'string', 'max:500'],
        ]);

        $user = auth()->user();

        if (!Hash::check($validated['password'], $user->password)) {
            return $this->errorResponse('Password is incorrect.', 422);
        }

        $user->update(['status' => 'deleted']);
        $user->delete();

        return $this->successResponse(
            data: null,
            message: 'Account deleted successfully'
        );
    }

    /**
     * GET /api/v1/user/favorites
     */
    public function favorites(): JsonResponse
    {
        $user      = auth()->user();
        $favorites = $this->readingService->getFavorites($user);

        return $this->successResponse(
            data: $favorites,
            message: 'Favorites retrieved successfully'
        );
    }

    /**
     * POST /api/v1/user/favorites
     */
    public function addFavorite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:book,audiobook'],
            'id'   => ['required', 'integer', 'min:1'],
        ]);

        $user  = auth()->user();

        if ($validated['type'] === 'book') {
            $added = $this->readingService->toggleBookFavorite($user, $validated['id']);
        } else {
            $added = $this->readingService->toggleAudioFavorite($user, $validated['id']);
        }

        return $this->successResponse(
            data: ['favorited' => $added],
            message: $added ? 'Added to favorites' : 'Removed from favorites'
        );
    }

    /**
     * DELETE /api/v1/user/favorites/{type}/{id}
     */
    public function removeFavorite(string $type, int $id): JsonResponse
    {
        $user = auth()->user();

        if ($type === 'book') {
            $this->readingService->toggleBookFavorite($user, $id);
        } else {
            $this->readingService->toggleAudioFavorite($user, $id);
        }

        return $this->successResponse(
            data: null,
            message: 'Removed from favorites'
        );
    }

    /**
     * GET /api/v1/user/bookshelf
     */
    public function bookshelf(): JsonResponse
    {
        $user    = auth()->user();
        $history = $this->readingService->getUserProgress($user);

        return $this->successResponse(
            data: $history,
            message: 'Bookshelf retrieved successfully'
        );
    }

    /**
     * GET /api/v1/user/downloads
     */
    public function downloads(): JsonResponse
    {
        $user = auth()->user();

        $downloads = \App\Domain\Reading\Models\ReadingProgress::with(['book.author'])
            ->where('user_id', $user->id)
            ->whereNotNull('downloaded_at')
            ->latest('downloaded_at')
            ->paginate(20);

        return $this->paginatedResponse(
            data: $downloads->items(),
            paginator: $downloads,
            message: 'Downloads retrieved successfully'
        );
    }
}
