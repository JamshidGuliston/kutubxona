<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsResource\Pages;

use App\Filament\Admin\Resources\NewsResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Resources\Pages\CreateRecord;

class CreateNews extends CreateRecord
{
    use HandlesTranslations;

    protected static string $resource = NewsResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractTranslations($data);
        $data['tenant_id'] = auth()->user()->tenant_id;
        $data['author_id'] = $data['author_id'] ?? auth()->id();
        return $data;
    }
}
