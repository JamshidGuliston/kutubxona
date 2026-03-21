<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookResource\Pages;

use App\Filament\Admin\Resources\BookResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditBook extends EditRecord
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewPdf')
                ->label("PDF ko'rish")
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->visible(fn () => filled($this->record->pdf_path))
                ->slideOver()
                ->modalWidth('screen')
                ->modalHeading(fn () => $this->record->title . ' — PDF')
                ->modalContent(function () {
                    $url = Storage::disk('uploads')->url($this->record->pdf_path);
                    return view('filament.modals.pdf-viewer', ['url' => $url]);
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Yopish'),

            Action::make('listenAudio')
                ->label('Audio tinglash')
                ->icon('heroicon-o-musical-note')
                ->color('success')
                ->visible(fn () => filled($this->record->audio_path))
                ->slideOver()
                ->modalWidth('md')
                ->modalHeading(fn () => $this->record->title . ' — Audio')
                ->modalContent(function () {
                    $url = Storage::disk('uploads')->url($this->record->audio_path);
                    return view('filament.modals.audio-player', ['url' => $url, 'title' => $this->record->title]);
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Yopish'),

            DeleteAction::make(),
        ];
    }
}
