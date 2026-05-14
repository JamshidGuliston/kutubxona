<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Localization\Models\TenantLanguage;
use App\Filament\Admin\Resources\TenantLanguageResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantLanguageResource extends Resource
{
    protected static ?string $model = TenantLanguage::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-language';
    protected static string|\UnitEnum|null $navigationGroup = 'Sozlamalar';
    protected static ?string $navigationLabel = 'Tillar';
    protected static ?string $modelLabel = 'Til';
    protected static ?string $pluralModelLabel = 'Tillar';
    protected static ?int $navigationSort = 90;

    public static function getEloquentQuery(): Builder
    {
        $tenantId = auth()->user()?->tenant_id;
        return parent::getEloquentQuery()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('sort_order');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Kod')
                ->required()
                ->maxLength(10)
                ->regex('/^[a-z]{2}(-[a-z]{2,4})?$/i')
                ->helperText('Masalan: uz, ru, en, uz-cyrl')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) =>
                    $rule->where('tenant_id', auth()->user()->tenant_id)),
            TextInput::make('name')
                ->label('Nomi (ingliz)')
                ->required()
                ->maxLength(100)
                ->helperText('Admin uchun: Uzbek, Russian, English'),
            TextInput::make('native_name')
                ->label("O'z tilida nomi")
                ->required()
                ->maxLength(100)
                ->helperText("Frontend selektorida ko'rinadi: O'zbekcha, Русский, English"),
            TextInput::make('flag_emoji')
                ->label('Bayroq')
                ->maxLength(10)
                ->helperText('Ixtiyoriy: 🇺🇿 🇷🇺 🇬🇧'),
            Toggle::make('is_default')->label('Asosiy til'),
            Toggle::make('is_active')->label('Faol')->default(true),
            TextInput::make('sort_order')
                ->label('Tartib')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('flag_emoji')->label('')->size('lg'),
                TextColumn::make('code')->label('Kod')->badge()->sortable(),
                TextColumn::make('name')->label('Nomi')->sortable()->searchable(),
                TextColumn::make('native_name')->label("O'z tilida"),
                IconColumn::make('is_default')->label('Asosiy')->boolean(),
                IconColumn::make('is_active')->label('Faol')->boolean(),
                TextColumn::make('sort_order')->label('Tartib')->sortable(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenantLanguages::route('/'),
            'create' => Pages\CreateTenantLanguage::route('/create'),
            'edit'   => Pages\EditTenantLanguage::route('/{record}/edit'),
        ];
    }
}
