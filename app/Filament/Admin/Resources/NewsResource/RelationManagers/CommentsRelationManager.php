<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsResource\RelationManagers;

use App\Domain\News\Models\NewsComment;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Sharhlar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->label('Matn')->disabled()->rows(4),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Foydalanuvchi'),
                TextColumn::make('body')->label('Sharh')->limit(80)->wrap(),
                IconColumn::make('is_approved')->label('Tasdiqlangan')->boolean(),
                TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_approved')->label('Tasdiq holati'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('approve')
                    ->label('Tasdiqlash')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (NewsComment $r) => ! $r->is_approved)
                    ->action(fn (NewsComment $r) => $r->update(['is_approved' => true])),
                DeleteAction::make(),
            ]);
    }
}
