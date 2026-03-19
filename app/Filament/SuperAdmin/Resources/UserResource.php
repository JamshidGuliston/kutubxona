<?php

declare(strict_types=1);

namespace App\Filament\SuperAdmin\Resources;

use App\Domain\User\Models\User;
use App\Filament\SuperAdmin\Resources\UserResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Foydalanuvchilar';

    protected static ?string $modelLabel = 'Foydalanuvchi';

    protected static ?string $pluralModelLabel = 'Foydalanuvchilar';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Ism')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            TextInput::make('password')
                ->label('Parol')
                ->password()
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create'),

            Select::make('tenant_id')
                ->label('Tenant')
                ->relationship('tenant', 'name', fn ($query) => $query->withoutGlobalScopes())
                ->required()
                ->searchable()
                ->preload(),

            Select::make('roles')
                ->label('Rol')
                ->multiple()
                ->options([
                    'super_admin'    => 'Super Admin',
                    'tenant_admin'   => 'Tenant Admin',
                    'tenant_manager' => 'Tenant Manager',
                    'user'           => 'Foydalanuvchi',
                ])
                ->afterStateHydrated(function ($component, $record) {
                    if ($record) {
                        $component->state(
                            $record->roles()->pluck('name')->toArray()
                        );
                    }
                }),

            Select::make('status')
                ->label('Holat')
                ->options([
                    'active'    => 'Faol',
                    'inactive'  => 'Nofaol',
                    'suspended' => 'Bloklangan',
                ])
                ->default('active')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Ism')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->sortable(),

                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->badge(),

                TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active'    => 'success',
                        'inactive'  => 'gray',
                        'suspended' => 'danger',
                        default     => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Yaratilgan')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tenant')
                    ->label('Tenant')
                    ->relationship('tenant', 'name', fn ($query) => $query->withoutGlobalScopes()),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
