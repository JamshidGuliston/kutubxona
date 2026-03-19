<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\User\Models\User
 */
final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'ulid'               => $this->ulid,
            'name'               => $this->name,
            'email'              => $this->email,
            'avatar_url'         => $this->avatar_url,
            'status'             => $this->status,
            'locale'             => $this->locale,
            'is_email_verified'  => $this->isEmailVerified(),
            'email_verified_at'  => $this->email_verified_at?->toIso8601String(),
            'last_login_at'      => $this->last_login_at?->toIso8601String(),
            'preferences'        => $this->preferences,

            'roles' => $this->whenLoaded('roles', fn () =>
                $this->roles->pluck('name')->toArray()
            ),

            'permissions' => $this->when(
                $this->relationLoaded('permissions') || $this->isSuperAdmin(),
                fn () => $this->isSuperAdmin() ? ['*'] : $this->getAllPermissions()->pluck('name')->toArray()
            ),

            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id'   => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
            ]),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
