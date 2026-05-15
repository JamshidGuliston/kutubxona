<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsCategoryResource\Pages;

use App\Filament\Admin\Resources\NewsCategoryResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsCategory extends CreateRecord
{
    use HandlesTranslations;

    protected static string $resource = NewsCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractTranslations($data);
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
