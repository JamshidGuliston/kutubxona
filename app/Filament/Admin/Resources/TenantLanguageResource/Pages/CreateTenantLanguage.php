<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantLanguageResource\Pages;

use App\Filament\Admin\Resources\TenantLanguageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantLanguage extends CreateRecord
{
    protected static string $resource = TenantLanguageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
