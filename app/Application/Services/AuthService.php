<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Auth\LoginDTO;
use App\Application\DTOs\Auth\RegisterDTO;
use App\Domain\User\Models\User;
use App\Infrastructure\Repositories\UserRepository;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * Register a new user within the current tenant.
     *
     * @throws ValidationException
     */
    public function register(RegisterDTO $dto): array
    {
        // Check if email already exists in this tenant
        if ($this->userRepository->existsByEmail($dto->email, $dto->tenantId)) {
            throw ValidationException::withMessages([
                'email' => ['This email address is already registered.'],
            ]);
        }

        $user = $this->userRepository->create([
            'tenant_id'  => $dto->tenantId,
            'name'       => $dto->name,
            'email'      => $dto->email,
            'password'   => Hash::make($dto->password),
            'locale'     => $dto->locale,
            'status'     => 'active',
        ]);

        // Assign default user role within tenant context
        setPermissionsTeamId($dto->tenantId);
        $user->assignRole('user');

        // Fire registered event → sends verification email
        event(new Registered($user));

        Log::info('User registered', [
            'tenant_id' => $dto->tenantId,
            'user_id'   => $user->id,
            'email'     => $dto->email,
        ]);

        $tokens = $this->generateTokenPair($user);

        return array_merge($tokens, ['user' => $user->load('roles')]);
    }

    /**
     * Authenticate user and return JWT tokens.
     *
     * @throws ValidationException
     */
    public function login(LoginDTO $dto): array
    {
        $tenant = app('tenant');

        // Check brute force protection
        $this->checkBruteForce(request()->ip(), $dto->email);

        $user = $this->userRepository->findByEmailAndTenant($dto->email, $tenant->id);

        if (!$user || !Hash::check($dto->password, $user->password)) {
            $this->incrementFailedAttempts(request()->ip(), $dto->email);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->isBanned()) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been suspended. Please contact support.'],
            ]);
        }

        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Your account is not active.'],
            ]);
        }

        // Reset failed attempts on successful login
        $this->clearFailedAttempts(request()->ip(), $dto->email);

        // Record login
        $user->recordLogin(request()->ip());

        Log::info('User logged in', [
            'tenant_id' => $tenant->id,
            'user_id'   => $user->id,
            'ip'        => request()->ip(),
        ]);

        $tokens = $this->generateTokenPair($user);

        return array_merge($tokens, [
            'user' => $user->load(['roles', 'tenant']),
        ]);
    }

    /**
     * Logout: blacklist current JWT token.
     */
    public function logout(): void
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Throwable $e) {
            // Token already invalid or expired — ignore
            Log::debug('Logout: token already invalid', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Refresh access token using refresh token.
     *
     * @throws \RuntimeException
     */
    public function refreshToken(string $refreshToken): array
    {
        $cacheKey = "refresh_token:{$refreshToken}";
        $userId   = Cache::get($cacheKey);

        if (!$userId) {
            throw new \RuntimeException('Invalid or expired refresh token.');
        }

        $user = $this->userRepository->findById((int) $userId);
        if (!$user || !$user->isActive()) {
            throw new \RuntimeException('User not found or inactive.');
        }

        // Rotate: delete old refresh token
        Cache::forget($cacheKey);

        return $this->generateTokenPair($user);
    }

    /**
     * Get currently authenticated user.
     */
    public function me(): ?User
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Send password reset email.
     */
    public function forgotPassword(string $email): void
    {
        $tenant = app('tenant');
        $user   = $this->userRepository->findByEmailAndTenant($email, $tenant->id);

        if (!$user) {
            // Always return success to prevent email enumeration
            return;
        }

        $token = $user->generatePasswordResetToken();

        // Send email (queued)
        \Illuminate\Support\Facades\Mail::to($user->email)
            ->queue(new \App\Mail\PasswordResetMail($user, $token));

        Log::info('Password reset requested', [
            'tenant_id' => $tenant->id,
            'email'     => $email,
        ]);
    }

    /**
     * Reset password using token.
     *
     * @throws ValidationException
     */
    public function resetPassword(string $email, string $token, string $password): void
    {
        $tenant = app('tenant');
        $user   = $this->userRepository->findByEmailAndTenant($email, $tenant->id);

        if (!$user || !$user->isPasswordResetTokenValid($token)) {
            throw ValidationException::withMessages([
                'token' => ['This password reset token is invalid or has expired.'],
            ]);
        }

        $user->update([
            'password'               => Hash::make($password),
            'password_changed_at'    => now(),
            'password_reset_token'   => null,
            'password_reset_expires' => null,
        ]);

        // Invalidate all existing tokens for this user
        // (by setting JWT claim nbf to current time + 1)
        Cache::put("user_password_changed:{$user->id}", now()->timestamp, 86400 * 30);

        Log::info('Password reset completed', ['user_id' => $user->id]);
    }

    /**
     * Verify email with token.
     *
     * @throws ValidationException
     */
    public function verifyEmail(string $token): User
    {
        $tenant = app('tenant');
        $user   = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email_verification_token', $token)
            ->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'token' => ['Invalid email verification token.'],
            ]);
        }

        $user->update([
            'email_verified_at'       => now(),
            'email_verification_token'=> null,
        ]);

        return $user;
    }

    // ─── Token Management ────────────────────────────────────────────────────────

    private function generateTokenPair(User $user): array
    {
        // Set tenant claim in JWT
        $customClaims = ['tid' => $user->tenant_id];
        $accessToken  = JWTAuth::claims($customClaims)->fromUser($user);

        // Generate opaque refresh token (stored in Redis)
        $refreshToken = \Illuminate\Support\Str::random(80);
        $refreshTtl   = config('jwt.refresh_ttl', 43200); // minutes

        Cache::put(
            "refresh_token:{$refreshToken}",
            $user->id,
            now()->addMinutes($refreshTtl)
        );

        return [
            'token'         => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => config('jwt.ttl', 60) * 60, // seconds
        ];
    }

    // ─── Brute Force Protection ──────────────────────────────────────────────────

    private function checkBruteForce(string $ip, string $email): void
    {
        $ipKey    = "login_attempts:ip:{$ip}";
        $emailKey = "login_attempts:email:{$email}";

        $ipAttempts    = (int) Cache::get($ipKey, 0);
        $emailAttempts = (int) Cache::get($emailKey, 0);

        if ($ipAttempts >= 10 || $emailAttempts >= 10) {
            throw ValidationException::withMessages([
                'email' => ['Too many login attempts. Please try again in 15 minutes.'],
            ]);
        }
    }

    private function incrementFailedAttempts(string $ip, string $email): void
    {
        $ttl = 900; // 15 minutes
        Cache::add("login_attempts:ip:{$ip}", 0, $ttl);
        Cache::increment("login_attempts:ip:{$ip}");

        Cache::add("login_attempts:email:{$email}", 0, $ttl);
        Cache::increment("login_attempts:email:{$email}");
    }

    private function clearFailedAttempts(string $ip, string $email): void
    {
        Cache::forget("login_attempts:ip:{$ip}");
        Cache::forget("login_attempts:email:{$email}");
    }
}
