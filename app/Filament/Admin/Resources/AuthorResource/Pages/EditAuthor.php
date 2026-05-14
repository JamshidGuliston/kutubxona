<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuthorResource\Pages;

use App\Filament\Admin\Resources\AuthorResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAuthor extends EditRecord
{
    use HandlesTranslations;

    protected static string $resource = AuthorResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
