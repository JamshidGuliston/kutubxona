<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Auth;

use App\Application\DTOs\Auth\LoginDTO;
use App\Application\DTOs\Auth\RegisterDTO;
use App\Application\Services\AuthService;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Requests\Auth\LoginRequest;
use App\Interfaces\Http\Resources\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(name="Authentication", description="User authentication endpoints")
 */
final class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * @OA\Post(
     *   path="/api/v1/auth/register",
     *   tags={"Authentication"},
     *   summary="Register a new user",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","email","password","password_confirmation"},
     *       @OA\Property(property="name", type="string", example="Alisher"),
     *       @OA\Property(property="email", type="string", format="email"),
     *       @OA\Property(property="password", type="string", minLength=8),
     *       @OA\Property(property="password_confirmation", type="string"),
     *       @OA\Property(property="locale", type="string", example="uz")
     *     )
     *   ),
     *   @OA\Response(response=201, description="User registered successfully"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email:rfc,dns', 'max:255'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
            'locale'                => ['nullable', 'string', 'in:uz,ru,en'],
        ]);

        $tenant = app('tenant');
        $dto    = RegisterDTO::fromArray($validated, $tenant->id);

        $result = $this->authService->register($dto);

        return $this->successResponse(
            data: [
                'user'          => new UserResource($result['user']),
                'token'         => $result['token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
            ],
            message: 'Registration successful. Please verify your email.',
            status: 201
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/auth/login",
     *   tags={"Authentication"},
     *   summary="Login user",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email", type="string", format="email"),
     *       @OA\Property(property="password", type="string"),
     *       @OA\Property(property="device_name", type="string"),
     *       @OA\Property(property="remember_me", type="boolean")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Login successful"),
     *   @OA\Response(response=401, description="Invalid credentials"),
     *   @OA\Response(response=429, description="Too many requests")
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $dto    = LoginDTO::fromRequest($request);
        $result = $this->authService->login($dto);

        return $this->successResponse(
            data: [
                'user'          => new UserResource($result['user']),
                'token'         => $result['token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
            ],
            message: 'Login successful'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/auth/logout",
     *   tags={"Authentication"},
     *   summary="Logout user (invalidate JWT)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Logged out successfully")
     * )
     */
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return $this->successResponse(
            data: null,
            message: 'Logged out successfully'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/auth/refresh",
     *   tags={"Authentication"},
     *   summary="Refresh access token",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"refresh_token"},
     *       @OA\Property(property="refresh_token", type="string")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Token refreshed"),
     *   @OA\Response(response=401, description="Invalid refresh token")
     * )
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $tokens = $this->authService->refreshToken($validated['refresh_token']);

        return $this->successResponse(
            data: $tokens,
            message: 'Token refreshed'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/auth/me",
     *   tags={"Authentication"},
     *   summary="Get authenticated user profile",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Profile retrieved")
     * )
     */
    public function me(): JsonResponse
    {
        $user = auth()->user();

        return $this->successResponse(
            data: new UserResource($user->load(['roles', 'tenant'])),
            message: 'Profile retrieved'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/auth/forgot-password",
     *   tags={"Authentication"},
     *   summary="Request password reset email",
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       required={"email"},
     *       @OA\Property(property="email", type="string", format="email")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Reset email sent (if account exists)")
     * )
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $this->authService->forgotPassword($request->email);

        return $this->successResponse(
            data: null,
            message: 'If that email address is in our system, you will receive a password reset link.'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/auth/reset-password",
     *   tags={"Authentication"},
     *   summary="Reset password using token from email",
     *   @OA\Response(response=200, description="Password reset successful"),
     *   @OA\Response(response=422, description="Invalid or expired token")
     * )
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'                 => ['required', 'email'],
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
        ]);

        $this->authService->resetPassword(
            $validated['email'],
            $validated['token'],
            $validated['password']
        );

        return $this->successResponse(
            data: null,
            message: 'Password has been reset successfully. Please login with your new password.'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/auth/verify-email",
     *   tags={"Authentication"},
     *   summary="Verify email address",
     *   @OA\Response(response=200, description="Email verified"),
     *   @OA\Response(response=422, description="Invalid token")
     * )
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $user = $this->authService->verifyEmail($validated['token']);

        return $this->successResponse(
            data: new UserResource($user),
            message: 'Email verified successfully.'
        );
    }
}
