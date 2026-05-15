<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Localization\Models\TenantLanguage;
use App\Domain\News\Enums\NewsStatus;
use App\Domain\News\Models\News;
use App\Domain\News\Models\NewsCategory;
use App\Filament\Admin\Resources\NewsResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-newspaper';
    protected static string|\UnitEnum|null $navigationGroup = 'Yangiliklar';
    protected static ?string $navigationLabel = 'Yangiliklar';
    protected static ?string $modelLabel = 'Yangilik';
    protected static ?string $pluralModelLabel = 'Yangiliklar';
    protected static ?int $navigationSort = 10;

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
                static::translationTabs()->columnSpanFull(),
            ]),

            Section::make('Rasm')->schema([
                FileUpload::make('cover_image')
                    ->label('Asosiy rasm')
                    ->image()
                    ->imagePreviewHeight('200')
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/news')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120)
                    ->columnSpanFull(),
                FileUpload::make('thumbnail')
                    ->label('Kichik rasm (ixtiyoriy)')
                    ->image()
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/news/thumbs')
                    ->visibility('public')
                    ->maxSize(2048)
                    ->helperText("Bo'sh qoldirsangiz asosiy rasm ishlatiladi")
                    ->columnSpanFull(),
            ]),

            Section::make('Sozlamalar')->schema([
                Grid::make(2)->schema([
                    Select::make('news_category_id')
                        ->label('Kategoriya')
                        ->relationship('category', 'id')
                        ->getOptionLabelFromRecordUsing(fn (NewsCategory $r) => $r->trans('name'))
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Select::make('status')
                        ->label('Holat')
                        ->options(collect(NewsStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                        ->default(NewsStatus::Draft->value)
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Toggle::make('is_featured')->label('Hero pozitsiyasi'),
                    DateTimePicker::make('published_at')
                        ->label('Nashr sanasi')
                        ->helperText("Bo'sh qoldirilsa va holat 'Nashr qilingan' bo'lsa, hozir bilan to'ldiriladi. Kelajak sana = rejalashtirilgan."),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->disk('uploads')
                    ->height(48)->width(72)
                    ->defaultImageUrl('https://placehold.co/72x48/e2e8f0/94a3b8?text=News'),
                TextColumn::make('title')
                    ->label('Sarlavha')
                    ->getStateUsing(fn (News $r) => $r->trans('title'))
                    ->searchable(query: fn ($q, $s) => $q->whereHas('translations', fn ($t) => $t->where('title', 'ilike', "%{$s}%")))
                    ->limit(50),
                TextColumn::make('category.id')
                    ->label('Kategoriya')
                    ->formatStateUsing(fn ($state, News $r) => $r->category?->trans('name') ?? '—'),
                TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof NewsStatus ? $state->label() : NewsStatus::from((string) $state)->label())
                    ->color(fn ($state) => $state instanceof NewsStatus ? $state->color() : NewsStatus::from((string) $state)->color()),
                IconColumn::make('is_featured')->label('Hero')->boolean(),
                TextColumn::make('view_count')->label("Ko'rishlar")->sortable(),
                TextColumn::make('like_count')->label('Like')->sortable(),
                TextColumn::make('comment_count')->label('Sharhlar')->sortable(),
                TextColumn::make('published_at')->label('Nashr')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(NewsStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                TernaryFilter::make('is_featured')->label('Hero pozitsiyasi'),
            ])
            ->defaultSort('published_at', 'desc')
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListNews::route('/'),
            'create' => Pages\CreateNews::route('/create'),
            'edit'   => Pages\EditNews::route('/{record}/edit'),
        ];
    }

    protected static function translationTabs(): Tabs
    {
        $tenantId = auth()->user()?->tenant_id;
        $languages = TenantLanguage::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($languages->isEmpty()) {
            $code = config('app.locale', 'uz');
            return Tabs::make('Tarjimalar')->tabs([
                Tab::make($code)->icon('heroicon-o-language')->schema(static::translationFieldsFor($code, true)),
            ]);
        }

        return Tabs::make('Tarjimalar')->tabs(
            $languages->map(fn (TenantLanguage $lang) => Tab::make($lang->native_name)
                ->icon('heroicon-o-language')
                ->schema(static::translationFieldsFor($lang->code, $lang->is_default))
            )->all()
        );
    }

    /** @return array<int, mixed> */
    protected static function translationFieldsFor(string $locale, bool $isDefault): array
    {
        return [
            TextInput::make("translations.{$locale}.title")
                ->label('Sarlavha')->required($isDefault)->maxLength(500)->columnSpanFull(),
            TextInput::make("translations.{$locale}.slug")
                ->label('URL slug')->maxLength(500)->columnSpanFull()
                ->helperText("Bo'sh qoldirsangiz avtomatik yaratiladi"),
            Textarea::make("translations.{$locale}.excerpt")
                ->label('Qisqacha matn')->rows(3)->columnSpanFull(),
            RichEditor::make("translations.{$locale}.body")
                ->label('Asosiy matn')
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'orderedList', 'bulletList',
                    'h2', 'h3', 'blockquote', 'codeBlock',
                ])
                ->required($isDefault)
                ->columnSpanFull(),
            TextInput::make("translations.{$locale}.meta_title")
                ->label('SEO sarlavha')->maxLength(255)->columnSpanFull(),
            Textarea::make("translations.{$locale}.meta_description")
                ->label('SEO tavsif')->rows(2)->columnSpanFull(),
        ];
    }
}
