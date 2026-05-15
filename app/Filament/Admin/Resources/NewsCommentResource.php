<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\News\Models\NewsComment;
use App\Filament\Admin\Resources\NewsCommentResource\Pages;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class NewsCommentResource extends Resource
{
    protected static ?string $model = NewsComment::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static string|\UnitEnum|null $navigationGroup = 'Yangiliklar';
    protected static ?string $navigationLabel = 'Sharhlar (moderatsiya)';
    protected static ?string $modelLabel = 'Sharh';
    protected static ?string $pluralModelLabel = 'Sharhlar';
    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $count = NewsComment::where('is_approved', false)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function canCreate(): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('news.id')
                    ->label('Yangilik')
                    ->formatStateUsing(fn ($state, NewsComment $r) => $r->news?->trans('title') ?? '—')
                    ->url(fn (NewsComment $r) => $r->news
                        ? \App\Filament\Admin\Resources\NewsResource::getUrl('edit', ['record' => $r->news_id])
                        : null)
                    ->limit(40),
                TextColumn::make('user.name')->label('Foydalanuvchi'),
                TextColumn::make('body')->label('Sharh')->limit(100)->wrap(),
                IconColumn::make('is_approved')->label('Tasdiq')->boolean(),
                TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_approved')
                    ->label('Holat')
                    ->trueLabel('Tasdiqlangan')
                    ->falseLabel('Kutilmoqda')
                    ->placeholder('Hammasi')
                    ->default(false),
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
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkApprove')
                        ->label('Tanlanganlarini tasdiqlash')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(function (Collection $records): void {
                            $records->each(fn (NewsComment $r) => $r->update(['is_approved' => true]));
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNewsComments::route('/'),
        ];
    }
}
