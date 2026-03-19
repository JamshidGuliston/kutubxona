<?php

declare(strict_types=1);

namespace App\Application\DTOs\Auth;

final readonly class RegisterDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $locale,
        public int $tenantId,
    ) {}

    public static function fromArray(array $data, int $tenantId): self
    {
        return new self(
            name:     trim($data['name']),
            email:    strtolower(trim($data['email'])),
            password: $data['password'],
            locale:   $data['locale'] ?? 'uz',
            tenantId: $tenantId,
        );
    }
}
