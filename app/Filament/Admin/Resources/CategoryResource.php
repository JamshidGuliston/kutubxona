<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Library\Models\Category;
use App\Domain\Localization\Models\TenantLanguage;
use App\Filament\Admin\Resources\CategoryResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Kategoriyalar';

    protected static ?string $modelLabel = 'Kategoriya';

    protected static ?string $pluralModelLabel = 'Kategoriyalar';

    protected static ?int $navigationSort = 3;

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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            static::translationTabs()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nomi')
                    ->getStateUsing(fn (\App\Domain\Library\Models\Category $record): ?string => $record->trans('name'))
                    ->searchable(query: function ($query, string $search): void {
                        $query->whereHas('translations', fn ($t) => $t->where('name', 'ilike', "%{$search}%"));
                    }),

                TextColumn::make('books_count')
                    ->label('Kitoblar')
                    ->counts('books')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Qo\'shilgan')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit'   => Pages\EditCategory::route('/{record}/edit'),
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
            $fallbackCode = config('app.locale', 'uz');
            return Tabs::make('Tarjimalar')->tabs([
                Tab::make($fallbackCode)
                    ->icon('heroicon-o-language')
                    ->schema(static::translationFieldsFor($fallbackCode, true)),
            ]);
        }

        return Tabs::make('Tarjimalar')->tabs(
            $languages->map(fn (TenantLanguage $lang) => Tab::make($lang->native_name)
                ->icon('heroicon-o-language')
                ->schema(static::translationFieldsFor($lang->code, $lang->is_default))
            )->all()
        );
    }

    protected static function translationFieldsFor(string $locale, bool $isDefault): array
    {
        return [
            TextInput::make("translations.{$locale}.name")
                ->label('Nomi')
                ->required($isDefault)
                ->maxLength(255),
            Textarea::make("translations.{$locale}.description")
                ->label('Tavsif')
                ->rows(3),
            TextInput::make("translations.{$locale}.slug")
                ->label('URL slug')
                ->helperText("Bo'sh qoldirsangiz avtomatik yaratiladi")
                ->maxLength(255),
        ];
    }
}
