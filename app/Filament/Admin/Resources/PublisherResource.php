<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Library\Models\Publisher;
use App\Domain\Localization\Models\TenantLanguage;
use App\Filament\Admin\Resources\PublisherResource\Pages;
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

class PublisherResource extends Resource
{
    protected static ?string $model = Publisher::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Nashriyotlar';

    protected static ?string $modelLabel = 'Nashriyot';

    protected static ?string $pluralModelLabel = 'Nashriyotlar';

    protected static ?int $navigationSort = 4;

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

            TextInput::make('website')
                ->label('Veb-sayt')
                ->url()
                ->maxLength(255),

            TextInput::make('founded_year')
                ->label('Tashkil etilgan yil')
                ->numeric(),

            TextInput::make('country')
                ->label('Mamlakat')
                ->maxLength(100),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nomi')
                    ->getStateUsing(fn (\App\Domain\Library\Models\Publisher $record): ?string => $record->trans('name'))
                    ->searchable(query: function ($query, string $search): void {
                        $query->whereHas('translations', fn ($t) => $t->where('name', 'ilike', "%{$search}%"));
                    }),

                TextColumn::make('country')
                    ->label('Mamlakat'),

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
            'index'  => Pages\ListPublishers::route('/'),
            'create' => Pages\CreatePublisher::route('/create'),
            'edit'   => Pages\EditPublisher::route('/{record}/edit'),
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
