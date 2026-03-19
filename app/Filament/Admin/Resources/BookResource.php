<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Book\Models\Book;
use App\Filament\Admin\Resources\BookResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BookResource extends Resource
{
    protected static ?string $model = Book::class;

    public static function canCreate(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $user = auth()->user();
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->when($user, fn ($q) => $q->where('tenant_id', $user->tenant_id));
    }

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Kitoblar';

    protected static ?string $modelLabel = 'Kitob';

    protected static ?string $pluralModelLabel = 'Kitoblar';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Sarlavha')
                ->required()
                ->maxLength(500)
                ->columnSpanFull(),

            Textarea::make('description')
                ->label('Tavsif')
                ->rows(4)
                ->columnSpanFull(),

            Select::make('author_id')
                ->label('Muallif')
                ->relationship('author', 'name')
                ->searchable()
                ->preload(),

            Select::make('publisher_id')
                ->label('Nashriyot')
                ->relationship('publisher', 'name')
                ->searchable()
                ->preload(),

            Select::make('category_id')
                ->label('Kategoriya')
                ->relationship('category', 'name')
                ->searchable()
                ->preload(),

            Select::make('status')
                ->label('Holat')
                ->options([
                    'draft'      => 'Qoralama',
                    'published'  => 'Nashr qilingan',
                    'archived'   => 'Arxivlangan',
                    'processing' => 'Jarayonda',
                ])
                ->default('draft')
                ->required(),

            TextInput::make('language')
                ->label('Til')
                ->default('uz')
                ->maxLength(10),

            TextInput::make('isbn')
                ->label('ISBN')
                ->maxLength(20),

            TextInput::make('pages')
                ->label('Sahifalar soni')
                ->numeric(),

            DatePicker::make('published_at')
                ->label('Nashr sanasi'),

            Toggle::make('is_featured')
                ->label('Tavsiya etilgan'),

            Toggle::make('is_free')
                ->label('Bepul')
                ->default(true),

            Toggle::make('is_downloadable')
                ->label('Yuklab olish mumkin')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Sarlavha')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('author.name')
                    ->label('Muallif')
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Kategoriya'),

                TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published'  => 'success',
                        'draft'      => 'gray',
                        'archived'   => 'warning',
                        'processing' => 'info',
                        default      => 'gray',
                    }),

                TextColumn::make('view_count')
                    ->label('Ko\'rishlar')
                    ->sortable(),

                TextColumn::make('download_count')
                    ->label('Yuklamalar')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Qo\'shilgan')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Holat')
                    ->options([
                        'draft'     => 'Qoralama',
                        'published' => 'Nashr qilingan',
                        'archived'  => 'Arxivlangan',
                    ]),

                TernaryFilter::make('is_featured')
                    ->label('Tavsiya etilgan'),

                TernaryFilter::make('is_free')
                    ->label('Bepul'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBooks::route('/'),
            'create' => Pages\CreateBook::route('/create'),
            'edit'   => Pages\EditBook::route('/{record}/edit'),
        ];
    }
}
