<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Book\Models\Book;
use App\Filament\Admin\Resources\BookResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class BookResource extends Resource
{
    protected static ?string $model = Book::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Kitoblar';
    protected static ?string $modelLabel = 'Kitob';
    protected static ?string $pluralModelLabel = 'Kitoblar';
    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool { return true; }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $user = auth()->user();
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->when($user, fn ($q) => $q->where('tenant_id', $user->tenant_id));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asosiy')->schema([
                TextInput::make('title')->label('Sarlavha')->required()->maxLength(500)->columnSpanFull(),
                TextInput::make('subtitle')->label('Kichik sarlavha')->maxLength(500)->columnSpanFull(),
                Textarea::make('description')->label('Tavsif')->rows(4)->columnSpanFull(),
                Grid::make(2)->schema([
                    Select::make('author_id')->label('Muallif')
                        ->relationship('author', 'name')->searchable()->preload()
                        ->createOptionForm([TextInput::make('name')->label('Ism')->required()]),
                    Select::make('publisher_id')->label('Nashriyot')
                        ->relationship('publisher', 'name')->searchable()->preload(),
                ]),
                Grid::make(2)->schema([
                    Select::make('category_id')->label('Kategoriya')
                        ->relationship('category', 'name')->searchable()->preload(),
                    Select::make('status')->label('Holat')
                        ->options([
                            'draft'      => 'Qoralama',
                            'published'  => 'Nashr qilingan',
                            'archived'   => 'Arxivlangan',
                            'processing' => 'Jarayonda',
                        ])
                        ->default('draft')->required(),
                ]),
            ]),

            Section::make('Muqova rasmi')->schema([
                FileUpload::make('cover_image')
                    ->label('Muqova (to\'liq o\'lcham)')
                    ->image()
                    ->imagePreviewHeight('220')
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/covers')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120)
                    ->helperText('Maks 5MB — JPEG, PNG yoki WebP')
                    ->columnSpanFull(),

                FileUpload::make('cover_thumbnail')
                    ->label('Thumbnail (kichik muqova)')
                    ->image()
                    ->imagePreviewHeight('150')
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/covers/thumbs')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)
                    ->helperText('Maks 2MB — katalogda ko\'rinadigan kichik rasm')
                    ->columnSpanFull(),
            ]),

            Section::make('Kitob fayllari')->schema([
                FileUpload::make('pdf_path')
                    ->label('PDF fayl (kitob matni)')
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/books/pdf')
                    ->visibility('public')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(102400)
                    ->downloadable()
                    ->helperText('Maks 100MB — faqat PDF format')
                    ->columnSpanFull(),

                FileUpload::make('audio_path')
                    ->label('Audio fayl (ixtiyoriy — audiokitob)')
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/books/audio')
                    ->visibility('public')
                    ->maxSize(512000)
                    ->helperText('Maks 500MB — MP3, M4A, OGG yoki WAV')
                    ->columnSpanFull(),
            ]),

            Section::make('Qo\'shimcha ma\'lumotlar')->schema([
                Grid::make(2)->schema([
                    TextInput::make('language')->label('Til')->default('uz')->maxLength(10),
                    TextInput::make('isbn')->label('ISBN')->maxLength(20),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('pages')->label('Sahifalar soni')->numeric()->minValue(1),
                    DatePicker::make('published_at')
                        ->label('Nashr sanasi')
                        ->displayFormat('d.m.Y')
                        ->native(false),
                ]),
            ]),

            Section::make('Sozlamalar')->columns(3)->schema([
                Toggle::make('is_featured')->label('Tavsiya etilgan')->default(false),
                Toggle::make('is_free')->label('Bepul')->default(true),
                Toggle::make('is_downloadable')->label('Yuklab olish mumkin')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover_image')
                    ->label('')
                    ->disk('uploads')
                    ->height(60)
                    ->width(40)
                    ->defaultImageUrl('https://placehold.co/40x60/e2e8f0/94a3b8?text=Book'),

                TextColumn::make('title')
                    ->label('Sarlavha')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->description(fn (Book $r): string => $r->author?->name ?? ''),

                TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'published'  => 'success',
                        'draft'      => 'gray',
                        'archived'   => 'warning',
                        'processing' => 'info',
                        default      => 'gray',
                    }),

                IconColumn::make('pdf_path')
                    ->label('PDF')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-text')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray'),

                IconColumn::make('audio_path')
                    ->label('Audio')
                    ->boolean()
                    ->trueIcon('heroicon-o-musical-note')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('info')
                    ->falseColor('gray'),

                TextColumn::make('view_count')->label('Ko\'rishlar')->sortable(),
                TextColumn::make('download_count')->label('Yuklamalar')->sortable(),
                TextColumn::make('created_at')->label('Qo\'shilgan')->dateTime('d.m.Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Holat')
                    ->options([
                        'draft'     => 'Qoralama',
                        'published' => 'Nashr qilingan',
                        'archived'  => 'Arxivlangan',
                    ]),
                TernaryFilter::make('is_featured')->label('Tavsiya etilgan'),
                TernaryFilter::make('is_free')->label('Bepul'),
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
