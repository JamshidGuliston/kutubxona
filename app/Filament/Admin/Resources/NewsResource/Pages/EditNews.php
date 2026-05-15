<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsResource\Pages;

use App\Filament\Admin\Resources\NewsResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNews extends EditRecord
{
    use HandlesTranslations;

    protected static string $resource = NewsResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
