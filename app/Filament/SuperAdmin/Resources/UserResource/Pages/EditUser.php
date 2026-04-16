<?php

declare(strict_types=1);

namespace App\Filament\SuperAdmin\Resources\UserResource\Pages;

use App\Filament\SuperAdmin\Resources\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['roles']);
        return $data;
    }

    protected function afterSave(): void
    {
        $roles = $this->data['roles'] ?? [];
        $roleModels = Role::whereIn('name', $roles)->where('guard_name', 'api')->get();
        $this->record->roles()->sync($roleModels->pluck('id'));
    }
}
