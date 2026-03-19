<?php

declare(strict_types=1);

namespace App\Filament\SuperAdmin\Resources\UserResource\Pages;

use App\Filament\SuperAdmin\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        $roles = $this->data['roles'] ?? [];
        if (!empty($roles)) {
            $roleModels = Role::whereIn('name', $roles)->where('guard_name', 'api')->get();
            $this->record->roles()->sync($roleModels->pluck('id'));
        }
    }
}
