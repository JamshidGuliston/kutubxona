<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Localization\Models\TenantLanguage;
use App\Domain\News\Models\NewsCategory;
use App\Filament\Admin\Resources\NewsCategoryResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NewsCategoryResource extends Resource
{
    protected static ?string $model = NewsCategory::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-tag';
    protected static string|\UnitEnum|null $navigationGroup = 'Yangiliklar';
    protected static ?string $navigationLabel = 'Yangiliklar kategoriyalari';
    protected static ?string $modelLabel = 'Yangilik kategoriyasi';
    protected static ?string $pluralModelLabel = 'Yangilik kategoriyalari';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            static::translationTabs()->columnSpanFull(),
            Select::make('parent_id')
                ->label('Yuqori kategoriya')
                ->relationship('parent', 'id')
                ->getOptionLabelFromRecordUsing(fn (NewsCategory $r): string => $r->trans('name') ?? "#{$r->id}")
                ->searchable()
                ->preload()
                ->nullable(),
            TextInput::make('icon')->label('Ikona (Heroicon nomi)')->maxLength(100),
            ColorPicker::make('color')->label('Rang'),
            TextInput::make('sort_order')->label('Tartib')->numeric()->default(0),
            Toggle::make('is_active')->label('Faol')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nomi')
                    ->getStateUsing(fn (NewsCategory $r) => $r->trans('name'))
                    ->searchable(query: fn ($q, $s) => $q->whereHas('translations', fn ($t) => $t->where('name', 'ilike', "%{$s}%"))),
                TextColumn::make('news_count')->counts('news')->label('Yangiliklar soni'),
                IconColumn::make('is_active')->label('Faol')->boolean(),
                TextColumn::make('sort_order')->label('Tartib')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListNewsCategories::route('/'),
            'create' => Pages\CreateNewsCategory::route('/create'),
            'edit'   => Pages\EditNewsCategory::route('/{record}/edit'),
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
            TextInput::make("translations.{$locale}.name")->label('Nomi')->required($isDefault)->maxLength(255),
            Textarea::make("translations.{$locale}.description")->label('Tavsif')->rows(3),
            TextInput::make("translations.{$locale}.slug")->label('Slug')->helperText("Bo'sh qoldirsangiz avtomatik yaratiladi"),
        ];
    }
}
