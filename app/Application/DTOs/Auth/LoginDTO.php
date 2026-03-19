<?php

declare(strict_types=1);

namespace App\Application\DTOs\Auth;

use App\Interfaces\Http\Requests\Auth\LoginRequest;

final readonly class LoginDTO
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $deviceName,
        public bool $rememberMe,
    ) {}

    public static function fromRequest(LoginRequest $request): self
    {
        return new self(
            email:      strtolower(trim($request->validated('email'))),
            password:   $request->validated('password'),
            deviceName: $request->validated('device_name'),
            rememberMe: (bool) $request->validated('remember_me', false),
        );
    }
}
