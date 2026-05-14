<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantLanguageResource\Pages;

use App\Filament\Admin\Resources\TenantLanguageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantLanguages extends ListRecords
{
    protected static string $resource = TenantLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
