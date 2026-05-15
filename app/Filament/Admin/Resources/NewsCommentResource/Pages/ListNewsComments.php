<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsCommentResource\Pages;

use App\Filament\Admin\Resources\NewsCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListNewsComments extends ListRecords
{
    protected static string $resource = NewsCommentResource::class;
}
