<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantLanguageResource\Pages;

use App\Filament\Admin\Resources\TenantLanguageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantLanguage extends EditRecord
{
    protected static string $resource = TenantLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
