<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuthorResource\Pages;

use App\Filament\Admin\Resources\AuthorResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Resources\Pages\CreateRecord;

class CreateAuthor extends CreateRecord
{
    use HandlesTranslations;

    protected static string $resource = AuthorResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractTranslations($data);
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
