<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PublisherResource\Pages;

use App\Filament\Admin\Resources\PublisherResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPublisher extends EditRecord
{
    use HandlesTranslations;

    protected static string $resource = PublisherResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
