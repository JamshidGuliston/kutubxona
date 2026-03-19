<?php

declare(strict_types=1);

namespace App\Filament\SuperAdmin\Resources;

use App\Domain\Tenant\Models\Tenant;
use App\Filament\SuperAdmin\Resources\TenantResource\Pages;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Tenantlar';

    protected static ?string $modelLabel = 'Tenant';

    protected static ?string $pluralModelLabel = 'Tenantlar';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nomi')
                ->required()
                ->maxLength(255),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(100),

            Select::make('status')
                ->label('Holat')
                ->options([
                    'active'    => 'Faol',
                    'inactive'  => 'Nofaol',
                    'suspended' => 'Bloklangan',
                    'pending'   => 'Kutilmoqda',
                ])
                ->required(),

            Select::make('plan_id')
                ->label('Tarif rejasi')
                ->relationship('plan', 'name')
                ->required(),

            TextInput::make('max_users')
                ->label('Max foydalanuvchilar')
                ->numeric()
                ->default(10),

            TextInput::make('max_books')
                ->label('Max kitoblar')
                ->numeric()
                ->default(100),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nomi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),

                TextColumn::make('plan.name')
                    ->label('Tarif')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active'    => 'success',
                        'inactive'  => 'gray',
                        'suspended' => 'danger',
                        'pending'   => 'warning',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),

                TextColumn::make('users_count')
                    ->label('Foydalanuvchilar')
                    ->counts('users')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Yaratilgan')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Holat')
                    ->options([
                        'active'    => 'Faol',
                        'inactive'  => 'Nofaol',
                        'suspended' => 'Bloklangan',
                        'pending'   => 'Kutilmoqda',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
