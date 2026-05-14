<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Library\Models\Author;
use App\Domain\Localization\Models\TenantLanguage;
use App\Filament\Admin\Resources\AuthorResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuthorResource extends Resource
{
    protected static ?string $model = Author::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user';

    protected static ?string $navigationLabel = 'Mualliflar';

    protected static ?string $modelLabel = 'Muallif';

    protected static ?string $pluralModelLabel = 'Mualliflar';

    protected static ?int $navigationSort = 2;

    /** Predefined directions list */
    public const DIRECTIONS = [
        'shoir'           => 'Shoir',
        'yozuvchi'        => 'Yozuvchi',
        'romansist'       => 'Romansist',
        'qissanavis'      => 'Qissanavis',
        'hikoyanavis'     => 'Hikoyanavis',
        'dramaturg'       => 'Dramaturg',
        'publitsist'      => 'Publitsist',
        'essayist'        => 'Essayist',
        'tarjimon'        => 'Tarjimon',
        'adabiyotshunos'  => 'Adabiyotshunos',
        'jurnalist'       => 'Jurnalist',
        'manbashunos'     => 'Manbashunos',
        'tarixchi'        => 'Tarixchi',
        'faylasuf'        => 'Faylasuf',
        'ilmiy'           => 'Ilmiy muallif',
    ];

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
            Section::make('Asosiy ma\'lumotlar')->schema([
                static::translationTabs()->columnSpanFull(),
            ]),

            Section::make('Yo\'nalishlar')
                ->description('Muallif faoliyat yuritgan yo\'nalishlarni tanlang (bir nechtasini tanlash mumkin)')
                ->schema([
                    CheckboxList::make('directions')
                        ->label('')
                        ->options(self::DIRECTIONS)
                        ->columns(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Shaxsiy ma\'lumotlar')->schema([
                Grid::make(2)->schema([
                    DatePicker::make('birth_date')
                        ->label('Tug\'ilgan sana')
                        ->displayFormat('d.m.Y')
                        ->native(false),

                    DatePicker::make('death_date')
                        ->label('Vafot etgan sana')
                        ->displayFormat('d.m.Y')
                        ->native(false)
                        ->after('birth_date'),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('nationality')
                        ->label('Millati')
                        ->maxLength(100),

                    TextInput::make('website')
                        ->label('Veb-sayt')
                        ->url()
                        ->prefix('https://')
                        ->maxLength(500),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Ism')
                    ->getStateUsing(fn (\App\Domain\Library\Models\Author $record): ?string => $record->trans('name'))
                    ->searchable(query: function ($query, string $search): void {
                        $query->whereHas('translations', fn ($t) => $t->where('name', 'ilike', "%{$search}%"));
                    }),

                TextColumn::make('directions')
                    ->label('Yo\'nalishlar')
                    ->formatStateUsing(function ($state): string {
                        if (empty($state)) return '—';
                        $dirs = is_array($state) ? $state : json_decode($state, true) ?? [];
                        return implode(', ', array_map(
                            fn ($d) => self::DIRECTIONS[$d] ?? $d,
                            $dirs
                        ));
                    })
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('birth_date')
                    ->label('Tug\'ilgan')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('death_date')
                    ->label('Vafot etgan')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('nationality')
                    ->label('Millati')
                    ->toggleable(),

                TextColumn::make('books_count')
                    ->label('Kitoblar')
                    ->counts('books')
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
            'index'  => Pages\ListAuthors::route('/'),
            'create' => Pages\CreateAuthor::route('/create'),
            'edit'   => Pages\EditAuthor::route('/{record}/edit'),
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
            ])->columnSpanFull();
        }

        return Tabs::make('Tarjimalar')->tabs(
            $languages->map(fn (TenantLanguage $lang) => Tab::make($lang->native_name)
                ->icon('heroicon-o-language')
                ->schema(static::translationFieldsFor($lang->code, $lang->is_default))
            )->all()
        )->columnSpanFull();
    }

    protected static function translationFieldsFor(string $locale, bool $isDefault): array
    {
        return [
            TextInput::make("translations.{$locale}.name")
                ->label('To\'liq ism')
                ->required($isDefault)
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make("translations.{$locale}.bio")
                ->label('Biografiya')
                ->rows(4)
                ->columnSpanFull(),
            TextInput::make("translations.{$locale}.slug")
                ->label('URL slug')
                ->helperText("Bo'sh qoldirsangiz avtomatik yaratiladi")
                ->maxLength(255),
        ];
    }
}
