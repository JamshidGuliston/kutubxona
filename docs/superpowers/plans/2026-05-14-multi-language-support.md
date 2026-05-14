# Multi-Language Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-tenant configurable languages and content translations for Book/Author/Category/Publisher/Tag, with locale-aware API responses and Filament admin UX.

**Architecture:** Translations live in separate per-entity tables (`book_translations`, etc.) keyed by `(parent_id, locale)`. A `HasTranslations` trait provides magic accessors (`$book->title`) that fall back to the tenant's default locale. A `ResolveLocaleMiddleware` sets `app()->setLocale()` from a priority chain (query → header → cookie → Accept-Language → tenant default). Filament admin shows one tab per active language for translatable fields.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL, Filament 4, Pest 2, JWT (tymon/jwt-auth), Spatie Permission.

**Reference spec:** `docs/superpowers/specs/2026-05-14-multi-language-design.md`

---

## File Structure

### New files

```
app/Domain/Localization/
  Contracts/
    HasTranslations.php                       — interface
  Traits/
    HasTranslations.php                       — shared trait behavior
  Models/
    TenantLanguage.php                        — tenant's language list
  Observers/
    TenantLanguageObserver.php                — enforce single is_default per tenant

app/Domain/Book/Models/
  BookTranslation.php                         — book translations

app/Domain/Library/Models/
  AuthorTranslation.php
  CategoryTranslation.php
  PublisherTranslation.php
  TagTranslation.php

app/Filament/Admin/Resources/
  TenantLanguageResource.php
  TenantLanguageResource/Pages/
    ListTenantLanguages.php
    CreateTenantLanguage.php
    EditTenantLanguage.php

app/Filament/Concerns/
  HandlesTranslations.php                     — shared trait for Create/Edit pages

app/Interfaces/Http/Middleware/
  ResolveLocaleMiddleware.php                 — locale resolution

app/Interfaces/Http/Controllers/V1/Localization/
  LanguageController.php                      — GET /api/v1/languages
  TranslationController.php                   — GET /api/v1/translations/{locale}

database/migrations/
  2026_05_14_000001_create_tenant_languages_table.php
  2026_05_14_000002_seed_default_tenant_languages.php
  2026_05_14_000003_create_book_translations_table.php
  2026_05_14_000004_create_author_translations_table.php
  2026_05_14_000005_create_category_translations_table.php
  2026_05_14_000006_create_publisher_translations_table.php
  2026_05_14_000007_create_tag_translations_table.php
  2026_05_14_000008_backfill_translations_from_existing_columns.php
  2026_05_14_000009_drop_translated_columns_from_main_tables.php

lang/
  uz.json
  ru.json
  en.json
  uz/validation.php                            — only if missing
  ru/validation.php
  en/validation.php

tests/
  Pest.php                                     — only if missing
  TestCase.php                                 — only if missing
  Feature/Localization/
    LocaleResolutionTest.php
    TenantLanguageManagementTest.php
    BookTranslationTest.php
    BookCrudWithTranslationsTest.php
    ApiBookListingLocaleTest.php
    TranslationsEndpointTest.php
    SlugUniquenessTest.php
  Unit/Localization/
    HasTranslationsTraitTest.php
```

### Modified files

```
app/Domain/Tenant/Models/Tenant.php           — add languages relations
app/Domain/Book/Models/Book.php               — add HasTranslations
app/Domain/Library/Models/Author.php          — add HasTranslations
app/Domain/Library/Models/Category.php        — add HasTranslations
app/Domain/Library/Models/Publisher.php       — add HasTranslations
app/Domain/Library/Models/Tag.php             — add HasTranslations

app/Filament/Admin/Resources/BookResource.php — Tabs for translatable fields
app/Filament/Admin/Resources/BookResource/Pages/CreateBook.php
app/Filament/Admin/Resources/BookResource/Pages/EditBook.php
app/Filament/Admin/Resources/AuthorResource.php
app/Filament/Admin/Resources/AuthorResource/Pages/CreateAuthor.php
app/Filament/Admin/Resources/AuthorResource/Pages/EditAuthor.php
app/Filament/Admin/Resources/CategoryResource.php
app/Filament/Admin/Resources/CategoryResource/Pages/CreateCategory.php
app/Filament/Admin/Resources/CategoryResource/Pages/EditCategory.php
app/Filament/Admin/Resources/PublisherResource.php
app/Filament/Admin/Resources/PublisherResource/Pages/CreatePublisher.php
app/Filament/Admin/Resources/PublisherResource/Pages/EditPublisher.php

app/Interfaces/Http/Resources/Book/BookResource.php           — emit translated fields
app/Interfaces/Http/Resources/Library/AuthorResource.php
app/Interfaces/Http/Resources/Library/CategoryResource.php
app/Interfaces/Http/Resources/Library/PublisherResource.php
                                                              (no TagResource exists today — skip)

bootstrap/app.php                             — register 'locale' middleware alias + add to tenant-api group
routes/api.php                                — register language + translations routes
```

---

## Stage 1 — Foundation: TenantLanguage

### Task 1.1: Migration — create `tenant_languages` table

**Files:**
- Create: `database/migrations/2026_05_14_000001_create_tenant_languages_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_languages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->string('native_name');
            $table->string('flag_emoji', 10)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_languages');
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected output: `INFO  Running migrations.` and `2026_05_14_000001_create_tenant_languages_table .................... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_14_000001_create_tenant_languages_table.php
git commit -m "feat(i18n): create tenant_languages table"
```

---

### Task 1.2: Data migration — seed `'uz'` default for existing tenants

**Files:**
- Create: `database/migrations/2026_05_14_000002_seed_default_tenant_languages.php`

- [ ] **Step 1: Write the data migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $tenants = DB::table('tenants')->select('id')->get();
            $now = now();

            foreach ($tenants as $tenant) {
                $exists = DB::table('tenant_languages')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', 'uz')
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tenant_languages')->insert([
                    'tenant_id'   => $tenant->id,
                    'code'        => 'uz',
                    'name'        => 'Uzbek',
                    'native_name' => "O'zbekcha",
                    'flag_emoji'  => '🇺🇿',
                    'is_default'  => true,
                    'is_active'   => true,
                    'sort_order'  => 0,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('tenant_languages')->where('code', 'uz')->delete();
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected output: `2026_05_14_000002_seed_default_tenant_languages ..................... DONE`

- [ ] **Step 3: Verify in tinker**

Run: `php artisan tinker --execute="DB::table('tenant_languages')->get()->dump();"`
Expected: at least one row per existing tenant with `code='uz'`, `is_default=1`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_14_000002_seed_default_tenant_languages.php
git commit -m "feat(i18n): seed uz as default language for existing tenants"
```

---

### Task 1.3: Create `TenantLanguage` model

**Files:**
- Create: `app/Domain/Localization/Models/TenantLanguage.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Localization\Models;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property string|null $flag_emoji
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
final class TenantLanguage extends Model
{
    protected $table = 'tenant_languages';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'native_name',
        'flag_emoji',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCurrentTenant(Builder $query): Builder
    {
        $tenantId = app()->has('tenant') ? app('tenant')->id : null;
        return $tenantId ? $query->where('tenant_id', $tenantId) : $query;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/Localization/Models/TenantLanguage.php
git commit -m "feat(i18n): add TenantLanguage model"
```

---

### Task 1.4: `TenantLanguageObserver` — enforce single default

**Files:**
- Create: `app/Domain/Localization/Observers/TenantLanguageObserver.php`
- Modify: `app/Domain/Localization/Models/TenantLanguage.php` (register observer)

- [ ] **Step 1: Write the observer**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Localization\Observers;

use App\Domain\Localization\Models\TenantLanguage;

final class TenantLanguageObserver
{
    public function saving(TenantLanguage $language): void
    {
        if (! $language->is_default) {
            return;
        }

        TenantLanguage::query()
            ->where('tenant_id', $language->tenant_id)
            ->when($language->exists, fn ($q) => $q->where('id', '!=', $language->id))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    public function deleting(TenantLanguage $language): void
    {
        if (! $language->is_default) {
            return;
        }

        $nextDefault = TenantLanguage::query()
            ->where('tenant_id', $language->tenant_id)
            ->where('id', '!=', $language->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        if ($nextDefault) {
            $nextDefault->update(['is_default' => true]);
        }
    }
}
```

- [ ] **Step 2: Register observer in model**

Modify `app/Domain/Localization/Models/TenantLanguage.php` — add to top after `use` statements:

```php
use App\Domain\Localization\Observers\TenantLanguageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
```

And add the attribute directly above the class declaration:

```php
#[ObservedBy([TenantLanguageObserver::class])]
final class TenantLanguage extends Model
```

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Localization/
git commit -m "feat(i18n): enforce single default language per tenant via observer"
```

---

### Task 1.5: Add language relations to `Tenant` model

**Files:**
- Modify: `app/Domain/Tenant/Models/Tenant.php`

- [ ] **Step 1: Add imports and relations**

Add to the `use` block at the top:

```php
use App\Domain\Localization\Models\TenantLanguage;
```

Add these methods after the existing `plan()` method (around line 122):

```php
    public function languages(): HasMany
    {
        return $this->hasMany(TenantLanguage::class, 'tenant_id')->orderBy('sort_order');
    }

    public function activeLanguages(): HasMany
    {
        return $this->hasMany(TenantLanguage::class, 'tenant_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function defaultLanguage(): HasOne
    {
        return $this->hasOne(TenantLanguage::class, 'tenant_id')
            ->where('is_default', true);
    }
```

- [ ] **Step 2: Add `default_locale` accessor**

Add this method after `getS3Prefix()` (end of class):

```php
    public function getDefaultLocaleAttribute(): string
    {
        return $this->defaultLanguage?->code ?? config('app.locale', 'uz');
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Tenant/Models/Tenant.php
git commit -m "feat(i18n): add language relations to Tenant model"
```

---

### Task 1.6: Filament resource — `TenantLanguageResource`

**Files:**
- Create: `app/Filament/Admin/Resources/TenantLanguageResource.php`
- Create: `app/Filament/Admin/Resources/TenantLanguageResource/Pages/ListTenantLanguages.php`
- Create: `app/Filament/Admin/Resources/TenantLanguageResource/Pages/CreateTenantLanguage.php`
- Create: `app/Filament/Admin/Resources/TenantLanguageResource/Pages/EditTenantLanguage.php`

- [ ] **Step 1: Write the resource**

```php
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
    protected static ?string $navigationGroup = 'Sozlamalar';
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
                ->label('O\'z tilida nomi')
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
                TextColumn::make('native_name')->label('O\'z tilida'),
                IconColumn::make('is_default')->label('Asosiy')->boolean(),
                IconColumn::make('is_active')->label('Faol')->boolean(),
                TextColumn::make('sort_order')->label('Tartib')->sortable(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
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
```

- [ ] **Step 2: Write the List page**

`app/Filament/Admin/Resources/TenantLanguageResource/Pages/ListTenantLanguages.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantLanguageResource\Pages;

use App\Filament\Admin\Resources\TenantLanguageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantLanguages extends ListRecords
{
    protected static string $resource = TenantLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

- [ ] **Step 3: Write the Create page**

`app/Filament/Admin/Resources/TenantLanguageResource/Pages/CreateTenantLanguage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantLanguageResource\Pages;

use App\Filament\Admin\Resources\TenantLanguageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantLanguage extends CreateRecord
{
    protected static string $resource = TenantLanguageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
```

- [ ] **Step 4: Write the Edit page**

`app/Filament/Admin/Resources/TenantLanguageResource/Pages/EditTenantLanguage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantLanguageResource\Pages;

use App\Filament\Admin\Resources\TenantLanguageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantLanguage extends EditRecord
{
    protected static string $resource = TenantLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
```

- [ ] **Step 5: Verify in browser**

Run: `php artisan filament:cache-components` then open `/admin/tenant-languages` — should show the resource. Create a new language (e.g. `ru` / Russian / Русский), toggle "Asosiy til" to true, save — verify the previous default was flipped to false.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Admin/Resources/TenantLanguageResource.php app/Filament/Admin/Resources/TenantLanguageResource/
git commit -m "feat(i18n): add Filament resource for tenant language management"
```

---

## Stage 2 — Translation infrastructure

### Task 2.1: `HasTranslations` contract (interface)

**Files:**
- Create: `app/Domain/Localization/Contracts/HasTranslations.php`

- [ ] **Step 1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Localization\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

interface HasTranslations
{
    /**
     * Returns all translations for this entity.
     */
    public function translations(): HasMany;

    /**
     * Returns the translation for the current app locale (eager-loadable).
     */
    public function translation(): HasOne;

    /**
     * Returns the value of a translatable field for the requested locale,
     * falling back to the tenant default locale when missing.
     */
    public function trans(string $field, ?string $locale = null): ?string;

    /**
     * Returns the list of translatable field names.
     *
     * @return list<string>
     */
    public function getTranslatableFields(): array;

    /**
     * Returns the fully-qualified class name of the translation model.
     */
    public function getTranslationModelClass(): string;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/Localization/Contracts/HasTranslations.php
git commit -m "feat(i18n): add HasTranslations contract"
```

---

### Task 2.2: `HasTranslations` trait

**Files:**
- Create: `app/Domain/Localization/Traits/HasTranslations.php`

- [ ] **Step 1: Write the trait**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Localization\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Magic + explicit translation accessor behavior.
 *
 * Consumers must:
 *   - implement {@see HasTranslations} contract
 *   - define static array TRANSLATABLE_FIELDS or override getTranslatableFields()
 *   - define static string TRANSLATION_MODEL or override getTranslationModelClass()
 */
trait HasTranslations
{
    public function translations(): HasMany
    {
        return $this->hasMany($this->getTranslationModelClass());
    }

    public function translation(): HasOne
    {
        return $this->hasOne($this->getTranslationModelClass())
            ->where('locale', app()->getLocale());
    }

    public function trans(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $primary = $translations->firstWhere('locale', $locale);
        if ($primary && filled($primary->{$field})) {
            return $primary->{$field};
        }

        // Fallback to tenant default locale
        $fallbackLocale = $this->tenantDefaultLocale();
        if ($fallbackLocale && $fallbackLocale !== $locale) {
            $fallback = $translations->firstWhere('locale', $fallbackLocale);
            if ($fallback && filled($fallback->{$field})) {
                return $fallback->{$field};
            }
        }

        return null;
    }

    public function getAttribute($key)
    {
        // Real columns and existing accessors win
        if ($this->hasGetMutator($key)
            || $this->hasAttributeGetMutator($key)
            || array_key_exists($key, $this->attributes)
            || $this->relationLoaded($key)
            || method_exists($this, $key)
        ) {
            return parent::getAttribute($key);
        }

        if (in_array($key, $this->getTranslatableFields(), true)) {
            return $this->trans($key);
        }

        return parent::getAttribute($key);
    }

    public function getTranslatableFields(): array
    {
        return static::TRANSLATABLE_FIELDS ?? [];
    }

    public function getTranslationModelClass(): string
    {
        if (! defined(static::class . '::TRANSLATION_MODEL')) {
            throw new \LogicException(
                static::class . ' must define const TRANSLATION_MODEL.'
            );
        }
        return static::TRANSLATION_MODEL;
    }

    /**
     * Looks up the tenant default locale from the related tenant.
     */
    protected function tenantDefaultLocale(): ?string
    {
        /** @var Model $this */
        if (! isset($this->tenant_id)) {
            return config('app.locale', 'uz');
        }

        $tenant = $this->relationLoaded('tenant')
            ? $this->tenant
            : \App\Domain\Tenant\Models\Tenant::find($this->tenant_id);

        return $tenant?->default_locale ?? config('app.locale', 'uz');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Domain/Localization/Traits/HasTranslations.php
git commit -m "feat(i18n): add HasTranslations trait with magic + explicit accessors"
```

---

### Task 2.3: Migration — `book_translations` table

**Files:**
- Create: `database/migrations/2026_05_14_000003_create_book_translations_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 500);
            $table->string('subtitle', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('slug', 500);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_translations');
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected: `2026_05_14_000003_create_book_translations_table .................... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_14_000003_create_book_translations_table.php
git commit -m "feat(i18n): create book_translations table"
```

---

### Task 2.4: Migrations — `author/category/publisher/tag_translations` tables

**Files:**
- Create: `database/migrations/2026_05_14_000004_create_author_translations_table.php`
- Create: `database/migrations/2026_05_14_000005_create_category_translations_table.php`
- Create: `database/migrations/2026_05_14_000006_create_publisher_translations_table.php`
- Create: `database/migrations/2026_05_14_000007_create_tag_translations_table.php`

- [ ] **Step 1: Write author_translations**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('author_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('authors')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('bio')->nullable();
            $table->string('slug');
            $table->timestamps();

            $table->unique(['author_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author_translations');
    }
};
```

- [ ] **Step 2: Write category_translations**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translations');
    }
};
```

- [ ] **Step 3: Write publisher_translations**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('publisher_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publisher_id')->constrained('publishers')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->timestamps();

            $table->unique(['publisher_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_translations');
    }
};
```

- [ ] **Step 4: Write tag_translations**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tag_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->timestamps();

            $table->unique(['tag_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_translations');
    }
};
```

- [ ] **Step 5: Run all four migrations**

Run: `php artisan migrate`
Expected: all four files report `DONE`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_14_00000{4,5,6,7}_*.php
git commit -m "feat(i18n): create author/category/publisher/tag translation tables"
```

---

### Task 2.5: Translation models (`BookTranslation`, etc.)

**Files:**
- Create: `app/Domain/Book/Models/BookTranslation.php`
- Create: `app/Domain/Library/Models/AuthorTranslation.php`
- Create: `app/Domain/Library/Models/CategoryTranslation.php`
- Create: `app/Domain/Library/Models/PublisherTranslation.php`
- Create: `app/Domain/Library/Models/TagTranslation.php`

- [ ] **Step 1: Write BookTranslation**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Book\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $book_id
 * @property string $locale
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $description
 * @property string $slug
 * @property string|null $meta_title
 * @property string|null $meta_description
 */
final class BookTranslation extends Model
{
    protected $table = 'book_translations';

    protected $fillable = [
        'tenant_id',
        'book_id',
        'locale',
        'title',
        'subtitle',
        'description',
        'slug',
        'meta_title',
        'meta_description',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $t): void {
            if (app()->has('tenant') && empty($t->tenant_id)) {
                $t->tenant_id = app('tenant')->id;
            }
            if (empty($t->slug) && !empty($t->title)) {
                $t->slug = static::generateUniqueSlug($t);
            }
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function generateUniqueSlug(self $t): string
    {
        $base = Str::slug($t->title);
        $slug = $base;
        $i    = 1;
        while (static::withoutGlobalScopes()
            ->where('tenant_id', $t->tenant_id)
            ->where('locale', $t->locale)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
```

- [ ] **Step 2: Write AuthorTranslation**

`app/Domain/Library/Models/AuthorTranslation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class AuthorTranslation extends Model
{
    protected $table = 'author_translations';

    protected $fillable = ['tenant_id', 'author_id', 'locale', 'name', 'bio', 'slug'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $t): void {
            if (app()->has('tenant') && empty($t->tenant_id)) {
                $t->tenant_id = app('tenant')->id;
            }
            if (empty($t->slug) && !empty($t->name)) {
                $t->slug = static::generateUniqueSlug($t);
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function generateUniqueSlug(self $t): string
    {
        $base = Str::slug($t->name);
        $slug = $base;
        $i    = 1;
        while (static::withoutGlobalScopes()
            ->where('tenant_id', $t->tenant_id)
            ->where('locale', $t->locale)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
```

- [ ] **Step 3: Write CategoryTranslation**

`app/Domain/Library/Models/CategoryTranslation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class CategoryTranslation extends Model
{
    protected $table = 'category_translations';

    protected $fillable = ['tenant_id', 'category_id', 'locale', 'name', 'description', 'slug'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $t): void {
            if (app()->has('tenant') && empty($t->tenant_id)) {
                $t->tenant_id = app('tenant')->id;
            }
            if (empty($t->slug) && !empty($t->name)) {
                $t->slug = static::generateUniqueSlug($t);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function generateUniqueSlug(self $t): string
    {
        $base = Str::slug($t->name);
        $slug = $base;
        $i    = 1;
        while (static::withoutGlobalScopes()
            ->where('tenant_id', $t->tenant_id)
            ->where('locale', $t->locale)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
```

- [ ] **Step 4: Write PublisherTranslation**

`app/Domain/Library/Models/PublisherTranslation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class PublisherTranslation extends Model
{
    protected $table = 'publisher_translations';

    protected $fillable = ['tenant_id', 'publisher_id', 'locale', 'name', 'description', 'slug'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $t): void {
            if (app()->has('tenant') && empty($t->tenant_id)) {
                $t->tenant_id = app('tenant')->id;
            }
            if (empty($t->slug) && !empty($t->name)) {
                $t->slug = static::generateUniqueSlug($t);
            }
        });
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'publisher_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function generateUniqueSlug(self $t): string
    {
        $base = Str::slug($t->name);
        $slug = $base;
        $i    = 1;
        while (static::withoutGlobalScopes()
            ->where('tenant_id', $t->tenant_id)
            ->where('locale', $t->locale)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
```

- [ ] **Step 5: Write TagTranslation**

`app/Domain/Library/Models/TagTranslation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class TagTranslation extends Model
{
    protected $table = 'tag_translations';

    protected $fillable = ['tenant_id', 'tag_id', 'locale', 'name', 'slug'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $t): void {
            if (app()->has('tenant') && empty($t->tenant_id)) {
                $t->tenant_id = app('tenant')->id;
            }
            if (empty($t->slug) && !empty($t->name)) {
                $t->slug = static::generateUniqueSlug($t);
            }
        });
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function generateUniqueSlug(self $t): string
    {
        $base = Str::slug($t->name);
        $slug = $base;
        $i    = 1;
        while (static::withoutGlobalScopes()
            ->where('tenant_id', $t->tenant_id)
            ->where('locale', $t->locale)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Book/Models/BookTranslation.php app/Domain/Library/Models/*Translation.php
git commit -m "feat(i18n): add translation models for Book/Author/Category/Publisher/Tag"
```

---

### Task 2.6: Wire `HasTranslations` into `Book` model

**Files:**
- Modify: `app/Domain/Book/Models/Book.php`

- [ ] **Step 1: Add trait + contract + constants**

Add to the imports block (after existing `use` statements):

```php
use App\Domain\Localization\Contracts\HasTranslations as HasTranslationsContract;
use App\Domain\Localization\Traits\HasTranslations;
```

Change the class declaration:

```php
final class Book extends Model implements HasTranslationsContract
{
    use HasFactory;
    use HasTranslations;
    use Searchable;
    use SoftDeletes;

    public const TRANSLATION_MODEL = BookTranslation::class;
    public const TRANSLATABLE_FIELDS = ['title', 'subtitle', 'description', 'slug', 'meta_title', 'meta_description'];
```

- [ ] **Step 2: Remove translatable fields from `$fillable`**

In the `$fillable` array, delete these lines:

```php
        'title',
        'slug',
        'subtitle',
        'description',
```

- [ ] **Step 3: Remove `scopeByLanguage` (no longer applies)**

Delete the entire `scopeByLanguage` method.

- [ ] **Step 4: Remove slug auto-generation from `creating` hook**

In `booted()`, delete this block from inside the `creating` closure (the slug logic moved to translations):

```php
            if (empty($book->slug) && !empty($book->title)) {
                $base = \Illuminate\Support\Str::slug($book->title);
                $slug = $base;
                $i = 1;
                while (static::withoutGlobalScopes()->where('tenant_id', $book->tenant_id)->where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $i++;
                }
                $book->slug = $slug;
            }
```

- [ ] **Step 5: Update `toSearchableArray()` to source from translations**

Replace the existing `toSearchableArray()` method:

```php
    public function toSearchableArray(): array
    {
        $defaultLocale = $this->tenant?->default_locale ?? config('app.locale', 'uz');
        $defaultTranslation = $this->translations->firstWhere('locale', $defaultLocale)
            ?? $this->translations->first();

        return [
            'id'             => $this->id,
            'tenant_id'      => $this->tenant_id,
            'title'          => $defaultTranslation?->title,
            'description'    => $defaultTranslation?->description,
            'author_name'    => $this->author?->trans('name'),
            'publisher_name' => $this->publisher?->trans('name'),
            'status'         => $this->status,
            'tags'           => $this->tags->map(fn ($t) => $t->trans('name'))->filter()->values()->all(),
        ];
    }
```

Remove the old `'language' => $this->language,` line.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Book/Models/Book.php
git commit -m "feat(i18n): wire HasTranslations into Book model"
```

---

### Task 2.7: Wire `HasTranslations` into Author/Category/Publisher/Tag

**Files:**
- Modify: `app/Domain/Library/Models/Author.php`
- Modify: `app/Domain/Library/Models/Category.php`
- Modify: `app/Domain/Library/Models/Publisher.php`
- Modify: `app/Domain/Library/Models/Tag.php`

- [ ] **Step 1: Author model**

Add to imports:
```php
use App\Domain\Library\Models\AuthorTranslation;
use App\Domain\Localization\Contracts\HasTranslations as HasTranslationsContract;
use App\Domain\Localization\Traits\HasTranslations;
```

Change class declaration to:
```php
final class Author extends Model implements HasTranslationsContract
{
    use HasTranslations;
    // ... existing traits

    public const TRANSLATION_MODEL = AuthorTranslation::class;
    public const TRANSLATABLE_FIELDS = ['name', 'bio', 'slug'];
```

Remove `name`, `slug`, `bio` from `$fillable`. Remove any slug auto-generation in `booted()`. Remove `whereFullText(['name'], ...)` from any scopes (it won't work after columns are dropped — replace with translation-table search later in Stage 5).

- [ ] **Step 2: Category model**

Same pattern. Imports + class implements + trait + constants:
```php
public const TRANSLATION_MODEL = CategoryTranslation::class;
public const TRANSLATABLE_FIELDS = ['name', 'description', 'slug'];
```
Remove `name`, `slug`, `description` from `$fillable`.

- [ ] **Step 3: Publisher model**

```php
public const TRANSLATION_MODEL = PublisherTranslation::class;
public const TRANSLATABLE_FIELDS = ['name', 'description', 'slug'];
```
Remove `name`, `slug`, `description` from `$fillable`.

- [ ] **Step 4: Tag model**

```php
public const TRANSLATION_MODEL = TagTranslation::class;
public const TRANSLATABLE_FIELDS = ['name', 'slug'];
```
Remove `name`, `slug` from `$fillable`.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Library/Models/Author.php app/Domain/Library/Models/Category.php app/Domain/Library/Models/Publisher.php app/Domain/Library/Models/Tag.php
git commit -m "feat(i18n): wire HasTranslations into Author/Category/Publisher/Tag"
```

---

## Stage 3 — Data migration

### Task 3.1: Backfill translations from existing columns

**Files:**
- Create: `database/migrations/2026_05_14_000008_backfill_translations_from_existing_columns.php`

- [ ] **Step 1: Write the backfill migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->backfillBooks();
            $this->backfillAuthors();
            $this->backfillCategories();
            $this->backfillPublishers();
            $this->backfillTags();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('book_translations')->delete();
            DB::table('author_translations')->delete();
            DB::table('category_translations')->delete();
            DB::table('publisher_translations')->delete();
            DB::table('tag_translations')->delete();
        });
    }

    private function backfillBooks(): void
    {
        DB::table('books')->orderBy('id')->chunkById(500, function ($books): void {
            $rows = [];
            foreach ($books as $book) {
                $rows[] = [
                    'tenant_id'   => $book->tenant_id,
                    'book_id'     => $book->id,
                    'locale'      => $book->language ?: 'uz',
                    'title'       => $book->title,
                    'subtitle'    => $book->subtitle,
                    'description' => $book->description,
                    'slug'        => $book->slug,
                    'created_at'  => $book->created_at ?? now(),
                    'updated_at'  => $book->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('book_translations')->insert($rows);
            }
        });
    }

    private function backfillAuthors(): void
    {
        DB::table('authors')->orderBy('id')->chunkById(500, function ($authors): void {
            $rows = [];
            foreach ($authors as $author) {
                $rows[] = [
                    'tenant_id'  => $author->tenant_id,
                    'author_id'  => $author->id,
                    'locale'     => 'uz',
                    'name'       => $author->name,
                    'bio'        => $author->bio,
                    'slug'       => $author->slug,
                    'created_at' => $author->created_at ?? now(),
                    'updated_at' => $author->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('author_translations')->insert($rows);
            }
        });
    }

    private function backfillCategories(): void
    {
        DB::table('categories')->orderBy('id')->chunkById(500, function ($categories): void {
            $rows = [];
            foreach ($categories as $category) {
                $rows[] = [
                    'tenant_id'   => $category->tenant_id,
                    'category_id' => $category->id,
                    'locale'      => 'uz',
                    'name'        => $category->name,
                    'description' => $category->description,
                    'slug'        => $category->slug,
                    'created_at'  => $category->created_at ?? now(),
                    'updated_at'  => $category->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('category_translations')->insert($rows);
            }
        });
    }

    private function backfillPublishers(): void
    {
        DB::table('publishers')->orderBy('id')->chunkById(500, function ($publishers): void {
            $rows = [];
            foreach ($publishers as $publisher) {
                $rows[] = [
                    'tenant_id'    => $publisher->tenant_id,
                    'publisher_id' => $publisher->id,
                    'locale'       => 'uz',
                    'name'         => $publisher->name,
                    'description'  => $publisher->description,
                    'slug'         => $publisher->slug,
                    'created_at'   => $publisher->created_at ?? now(),
                    'updated_at'   => $publisher->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('publisher_translations')->insert($rows);
            }
        });
    }

    private function backfillTags(): void
    {
        DB::table('tags')->orderBy('id')->chunkById(500, function ($tags): void {
            $rows = [];
            foreach ($tags as $tag) {
                $rows[] = [
                    'tenant_id'  => $tag->tenant_id,
                    'tag_id'     => $tag->id,
                    'locale'     => 'uz',
                    'name'       => $tag->name,
                    'slug'       => $tag->slug,
                    'created_at' => $tag->created_at ?? now(),
                    'updated_at' => $tag->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('tag_translations')->insert($rows);
            }
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected: `2026_05_14_000008_backfill_translations_from_existing_columns ........ DONE`

- [ ] **Step 3: Verify counts match**

Run: `php artisan tinker --execute="dump([
    'books' => DB::table('books')->count(),
    'book_translations' => DB::table('book_translations')->count(),
    'authors' => DB::table('authors')->count(),
    'author_translations' => DB::table('author_translations')->count(),
]);"`
Expected: each `*_translations` count equals its parent count.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_14_000008_backfill_translations_from_existing_columns.php
git commit -m "feat(i18n): backfill translation tables from existing columns"
```

---

### Task 3.2: Drop translated columns from main tables

**Files:**
- Create: `database/migrations/2026_05_14_000009_drop_translated_columns_from_main_tables.php`

- [ ] **Step 1: Write the drop migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropIndex(['language']);
            // Drop the fulltext index by name (Postgres ignores fullText, MySQL requires explicit drop)
            try { $table->dropFullText(['title', 'description']); } catch (\Throwable) {}
            $table->dropColumn(['title', 'subtitle', 'description', 'slug', 'language']);
        });

        Schema::table('authors', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'slug']);
            try { $table->dropFullText(['name']); } catch (\Throwable) {}
            $table->dropColumn(['name', 'slug', 'bio']);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropColumn(['name', 'slug', 'description']);
        });

        Schema::table('publishers', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropColumn(['name', 'slug', 'description']);
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropColumn(['name', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->string('title', 500)->nullable();
            $table->string('subtitle', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('slug', 500)->nullable();
            $table->string('language', 10)->default('uz');
        });

        Schema::table('authors', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('bio')->nullable();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
        });

        Schema::table('publishers', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->string('name', 100)->nullable();
            $table->string('slug', 100)->nullable();
        });

        // Re-copy default-locale data back from translations
        DB::transaction(function (): void {
            DB::statement("UPDATE books SET title = bt.title, subtitle = bt.subtitle, description = bt.description, slug = bt.slug, language = bt.locale FROM book_translations bt WHERE books.id = bt.book_id AND bt.locale = 'uz'");
            DB::statement("UPDATE authors SET name = at.name, bio = at.bio, slug = at.slug FROM author_translations at WHERE authors.id = at.author_id AND at.locale = 'uz'");
            DB::statement("UPDATE categories SET name = ct.name, description = ct.description, slug = ct.slug FROM category_translations ct WHERE categories.id = ct.category_id AND ct.locale = 'uz'");
            DB::statement("UPDATE publishers SET name = pt.name, description = pt.description, slug = pt.slug FROM publisher_translations pt WHERE publishers.id = pt.publisher_id AND pt.locale = 'uz'");
            DB::statement("UPDATE tags SET name = tt.name, slug = tt.slug FROM tag_translations tt WHERE tags.id = tt.tag_id AND tt.locale = 'uz'");
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected: `2026_05_14_000009_drop_translated_columns_from_main_tables ........... DONE`

- [ ] **Step 3: Smoke test the model**

Run: `php artisan tinker --execute="\$b = \App\Domain\Book\Models\Book::withoutGlobalScopes()->first(); echo 'title: ' . \$b->title . PHP_EOL; echo 'trans(ru): ' . (\$b->trans('title', 'ru') ?? 'NULL') . PHP_EOL;"`
Expected: `title: <Uzbek title>` (magic accessor falls back to uz translation), `trans(ru): NULL` (no Russian translation exists yet).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_14_000009_drop_translated_columns_from_main_tables.php
git commit -m "feat(i18n): drop translated columns from main tables (data moved to translation tables)"
```

---

## Stage 4 — Locale resolution

### Task 4.1: `ResolveLocaleMiddleware`

**Files:**
- Create: `app/Interfaces/Http/Middleware/ResolveLocaleMiddleware.php`

- [ ] **Step 1: Write the middleware**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Domain\Localization\Models\TenantLanguage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveLocaleMiddleware
 *
 * Priority chain:
 *   1. ?lang=  query parameter
 *   2. X-Locale request header
 *   3. locale  cookie
 *   4. Accept-Language header
 *   5. tenant.default_locale
 *   6. config('app.locale')
 *
 * Validates resolved locale against the current tenant's tenant_languages.
 * Falls back to tenant default silently if locale is inactive or not configured.
 */
final class ResolveLocaleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->has('tenant') ? app('tenant') : null;
        $allowedLocales = $this->getAllowedLocales($tenant?->id);
        $defaultLocale  = $tenant?->default_locale ?? config('app.locale', 'uz');

        $requested = $this->detectRequestedLocale($request, $defaultLocale);
        $locale = in_array($requested, $allowedLocales, true) ? $requested : $defaultLocale;

        app()->setLocale($locale);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        return $response;
    }

    private function detectRequestedLocale(Request $request, string $fallback): string
    {
        // 1. Query param
        if ($lang = $request->query('lang')) {
            return $this->normalize((string) $lang);
        }
        // 2. X-Locale header
        if ($lang = $request->header('X-Locale')) {
            return $this->normalize((string) $lang);
        }
        // 3. locale cookie
        if ($lang = $request->cookie('locale')) {
            return $this->normalize((string) $lang);
        }
        // 4. Accept-Language header
        if ($lang = $this->parseAcceptLanguage($request)) {
            return $this->normalize($lang);
        }
        // 5. Tenant default / app default
        return $fallback;
    }

    private function parseAcceptLanguage(Request $request): ?string
    {
        $header = $request->header('Accept-Language');
        if (! $header) {
            return null;
        }
        $items = AcceptHeader::fromString($header)->all();
        if (empty($items)) {
            return null;
        }
        $first = array_shift($items);
        return explode('-', $first->getValue())[0] ?? null;
    }

    private function normalize(string $locale): string
    {
        return strtolower(trim($locale));
    }

    /**
     * @return list<string>
     */
    private function getAllowedLocales(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [config('app.locale', 'uz')];
        }
        return Cache::remember(
            "tenant.{$tenantId}.locales",
            300,
            fn () => TenantLanguage::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->pluck('code')
                ->all()
        );
    }
}
```

- [ ] **Step 2: Register the middleware alias**

Modify `bootstrap/app.php`. Add to imports at the top:

```php
use App\Interfaces\Http\Middleware\ResolveLocaleMiddleware;
```

Inside `withMiddleware(function (Middleware $middleware): void { ... })`, in the `$middleware->alias([...])` call, add this line:

```php
            'locale'            => ResolveLocaleMiddleware::class,
```

And in the `appendToGroup('tenant-api', [...])` call, add it after `TenantMiddleware::class`:

```php
        $middleware->appendToGroup('tenant-api', [
            TenantMiddleware::class,
            ResolveLocaleMiddleware::class,
            TenantScopeMiddleware::class,
            RateLimitByTenant::class,
        ]);
```

- [ ] **Step 3: Apply middleware to api route group**

Modify `routes/api.php`. Change the v1 route group from:

```php
Route::prefix('v1')->middleware(['tenant', 'tenant.scope'])->group(function (): void {
```

to:

```php
Route::prefix('v1')->middleware(['tenant', 'locale', 'tenant.scope'])->group(function (): void {
```

- [ ] **Step 4: Cache invalidation on language change**

Modify `app/Domain/Localization/Observers/TenantLanguageObserver.php`. Add `saved` and `deleted` methods after `saving()`:

```php
    public function saved(TenantLanguage $language): void
    {
        \Illuminate\Support\Facades\Cache::forget("tenant.{$language->tenant_id}.locales");
    }

    public function deleted(TenantLanguage $language): void
    {
        \Illuminate\Support\Facades\Cache::forget("tenant.{$language->tenant_id}.locales");
    }
```

- [ ] **Step 5: Commit**

```bash
git add app/Interfaces/Http/Middleware/ResolveLocaleMiddleware.php bootstrap/app.php routes/api.php app/Domain/Localization/Observers/TenantLanguageObserver.php
git commit -m "feat(i18n): add ResolveLocaleMiddleware and wire into tenant-api group"
```

---

### Task 4.2: `GET /api/v1/languages` endpoint

**Files:**
- Create: `app/Interfaces/Http/Controllers/V1/Localization/LanguageController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Localization;

use App\Domain\Localization\Models\TenantLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

final class LanguageController extends Controller
{
    public function index(): JsonResponse
    {
        $tenant = app('tenant');
        $cacheKey = "tenant.{$tenant->id}.languages.public";

        $payload = Cache::remember($cacheKey, 300, function () use ($tenant): array {
            $languages = TenantLanguage::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            return [
                'default_locale' => $tenant->default_locale,
                'languages' => $languages->map(fn (TenantLanguage $l) => [
                    'code'        => $l->code,
                    'name'        => $l->name,
                    'native_name' => $l->native_name,
                    'flag'        => $l->flag_emoji,
                    'is_default'  => $l->is_default,
                ])->all(),
            ];
        });

        return response()->json($payload);
    }
}
```

- [ ] **Step 2: Register the route**

In `routes/api.php`, inside the public throttled group (around `Route::middleware('throttle:api')->group(...)`, after the search routes), add:

```php
        // Languages (public — no auth needed)
        Route::get('/languages', [\App\Interfaces\Http\Controllers\V1\Localization\LanguageController::class, 'index'])
             ->name('languages.index');
```

- [ ] **Step 3: Cache invalidation hook**

Modify `app/Domain/Localization/Observers/TenantLanguageObserver.php`. Update `saved()` and `deleted()`:

```php
    public function saved(TenantLanguage $language): void
    {
        \Illuminate\Support\Facades\Cache::forget("tenant.{$language->tenant_id}.locales");
        \Illuminate\Support\Facades\Cache::forget("tenant.{$language->tenant_id}.languages.public");
    }

    public function deleted(TenantLanguage $language): void
    {
        \Illuminate\Support\Facades\Cache::forget("tenant.{$language->tenant_id}.locales");
        \Illuminate\Support\Facades\Cache::forget("tenant.{$language->tenant_id}.languages.public");
    }
```

- [ ] **Step 4: Smoke test**

Start the dev server (`php artisan serve`) and hit `GET http://127.0.0.1:8000/api/v1/languages` with header `X-Tenant-ID: <id>`. Expected: JSON response with `default_locale` and `languages[]`.

- [ ] **Step 5: Commit**

```bash
git add app/Interfaces/Http/Controllers/V1/Localization/LanguageController.php routes/api.php app/Domain/Localization/Observers/TenantLanguageObserver.php
git commit -m "feat(i18n): add GET /api/v1/languages endpoint"
```

---

### Task 4.3: `GET /api/v1/translations/{locale}` endpoint

**Files:**
- Create: `app/Interfaces/Http/Controllers/V1/Localization/TranslationController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TranslationController extends Controller
{
    public function show(string $locale): JsonResponse
    {
        $locale = strtolower(preg_replace('/[^a-z\-]/i', '', $locale));
        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            throw new NotFoundHttpException("Translations for locale '{$locale}' not found.");
        }

        $payload = Cache::remember(
            "translations.json.{$locale}",
            3600,
            fn (): array => [
                'locale'       => $locale,
                'translations' => json_decode(file_get_contents($path), true) ?? [],
            ]
        );

        return response()->json($payload);
    }
}
```

- [ ] **Step 2: Register the route**

In `routes/api.php`, alongside the languages route:

```php
        Route::get('/translations/{locale}', [\App\Interfaces\Http\Controllers\V1\Localization\TranslationController::class, 'show'])
             ->name('translations.show');
```

- [ ] **Step 3: Commit**

```bash
git add app/Interfaces/Http/Controllers/V1/Localization/TranslationController.php routes/api.php
git commit -m "feat(i18n): add GET /api/v1/translations/{locale} endpoint"
```

---

## Stage 5 — API Resources (translated output)

### Task 5.1: Update API resources to source from translations

**Files:**
- Modify: `app/Interfaces/Http/Resources/Book/BookResource.php`
- Modify: `app/Interfaces/Http/Resources/Library/AuthorResource.php`
- Modify: `app/Interfaces/Http/Resources/Library/CategoryResource.php`
- Modify: `app/Interfaces/Http/Resources/Library/PublisherResource.php`
- (No `TagResource` exists today — skip; controllers return Tag models directly. Add a `TagResource` if you need translation fields exposed by API for tags.)

- [ ] **Step 1: Read each resource and find translatable keys**

For each resource file, locate any reference to `$this->title`, `$this->name`, `$this->slug`, `$this->description`, `$this->subtitle`, `$this->bio` and confirm they still appear — the magic accessor will handle translation automatically.

Add an optional `translations` key to each `toArray()` for admin clients:

For `BookResource::toArray()`, append before the closing `]`:

```php
            'translations' => $this->when(
                $request->boolean('with_translations'),
                fn () => $this->translations->keyBy('locale')->map(fn ($t) => [
                    'title'       => $t->title,
                    'subtitle'    => $t->subtitle,
                    'description' => $t->description,
                    'slug'        => $t->slug,
                ])
            ),
```

For `AuthorResource::toArray()`:

```php
            'translations' => $this->when(
                $request->boolean('with_translations'),
                fn () => $this->translations->keyBy('locale')->map(fn ($t) => [
                    'name' => $t->name,
                    'bio'  => $t->bio,
                    'slug' => $t->slug,
                ])
            ),
```

Apply analogous changes to CategoryResource, PublisherResource, TagResource (using their translatable fields).

- [ ] **Step 2: Eager-load `translations` to avoid N+1**

In each controller `index()`/`show()` method that returns these resources, add `->with('translations')` to the query. Example for `BookController`:

```php
$query = Book::query()->with(['translations', 'author.translations', 'publisher.translations', 'category.translations']);
```

Use `Explore` agent or grep for existing queries if you're unsure which controllers need this.

- [ ] **Step 3: Smoke test**

Run: `curl -H "X-Tenant-ID: 1" -H "X-Locale: uz" http://127.0.0.1:8000/api/v1/books | head -100`
Expected: each book has `title` and `description` populated from Uzbek translation.

- [ ] **Step 4: Commit**

```bash
git add app/Interfaces/Http/Resources/Book/ app/Interfaces/Http/Resources/Library/ app/Interfaces/Http/Controllers/V1/
git commit -m "feat(i18n): API resources emit translated fields and support with_translations flag"
```

---

## Stage 6 — Filament admin panel (translation tabs)

### Task 6.1: `HandlesTranslations` trait for Filament pages

**Files:**
- Create: `app/Filament/Concerns/HandlesTranslations.php`

- [ ] **Step 1: Write the trait**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Localization\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared lifecycle hooks for Filament Create/Edit pages whose record is
 * Translatable. Splits `translations.{locale}.*` form data into a separate
 * bag and writes them after the parent record is saved.
 */
trait HandlesTranslations
{
    /** @var array<string, array<string, mixed>> */
    protected array $translationsData = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->translationsData = $data['translations'] ?? [];
        unset($data['translations']);
        return $data;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        $this->persistTranslations();
    }

    protected function afterCreate(): void
    {
        $this->persistTranslations();
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        if (! $this->record instanceof HasTranslations) {
            return;
        }

        $existing = $this->record->translations->keyBy('locale');
        $payload  = $this->form->getRawState();
        $translationsBag = [];

        foreach ($existing as $locale => $translation) {
            foreach ($this->record->getTranslatableFields() as $field) {
                $translationsBag[$locale][$field] = $translation->{$field};
            }
        }

        $payload['translations'] = $translationsBag;
        $this->form->fill($payload);
    }

    private function persistTranslations(): void
    {
        if (empty($this->translationsData) || ! $this->record instanceof HasTranslations) {
            return;
        }

        $primaryField = $this->primaryTranslationField();

        foreach ($this->translationsData as $locale => $fields) {
            if (empty($fields[$primaryField])) {
                // Skip empty rows; also remove any stale row for this locale
                $this->record->translations()->where('locale', $locale)->delete();
                continue;
            }

            $payload = array_intersect_key(
                $fields,
                array_flip($this->record->getTranslatableFields())
            );

            $this->record->translations()->updateOrCreate(
                ['locale' => $locale],
                $payload
            );
        }
    }

    /**
     * Returns the primary translatable field whose presence indicates
     * a non-empty translation (e.g., 'title' for Book, 'name' for Author).
     */
    protected function primaryTranslationField(): string
    {
        return $this->record->getTranslatableFields()[0] ?? 'title';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Filament/Concerns/HandlesTranslations.php
git commit -m "feat(i18n): add HandlesTranslations trait for Filament pages"
```

---

### Task 6.2: Update `BookResource` form with translation tabs

**Files:**
- Modify: `app/Filament/Admin/Resources/BookResource.php`

- [ ] **Step 1: Add imports**

At the top, add:

```php
use App\Domain\Localization\Models\TenantLanguage;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
```

- [ ] **Step 2: Replace the "Asosiy" section with a translatable variant**

Find the existing `Section::make('Asosiy')->schema([...])` block. Replace its inner `TextInput::make('title')`, `TextInput::make('subtitle')`, and `Textarea::make('description')` items with a `Tabs` component:

```php
            Section::make('Asosiy')->schema([
                static::translationTabs(),

                Grid::make(2)->schema([
                    Select::make('author_id')->label('Muallif')
                        ->relationship('author', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($r) => $r->trans('name'))
                        ->searchable()->preload(),
                    Select::make('publisher_id')->label('Nashriyot')
                        ->relationship('publisher', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($r) => $r->trans('name'))
                        ->searchable()->preload(),
                ]),
                Grid::make(2)->schema([
                    Select::make('category_id')->label('Kategoriya')
                        ->relationship('category', 'id')
                        ->getOptionLabelFromRecordUsing(fn ($r) => $r->trans('name'))
                        ->searchable()->preload(),
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
```

- [ ] **Step 3: Add the `translationTabs()` static helper to the resource**

At the bottom of the `BookResource` class (before the closing `}`):

```php
    protected static function translationTabs(): Tabs
    {
        $tenantId = auth()->user()?->tenant_id;
        $languages = TenantLanguage::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return Tabs::make('Tarjimalar')
            ->tabs(
                $languages->map(fn (TenantLanguage $lang) => Tab::make($lang->native_name)
                    ->icon('heroicon-o-language')
                    ->schema([
                        TextInput::make("translations.{$lang->code}.title")
                            ->label('Sarlavha')
                            ->required($lang->is_default)
                            ->maxLength(500),
                        TextInput::make("translations.{$lang->code}.subtitle")
                            ->label('Kichik sarlavha')
                            ->maxLength(500),
                        Textarea::make("translations.{$lang->code}.description")
                            ->label('Tavsif')
                            ->rows(4),
                        TextInput::make("translations.{$lang->code}.slug")
                            ->label('URL slug')
                            ->helperText("Bo'sh qoldirsangiz avtomatik yaratiladi")
                            ->maxLength(500),
                        TextInput::make("translations.{$lang->code}.meta_title")
                            ->label('SEO sarlavha')
                            ->maxLength(255),
                        Textarea::make("translations.{$lang->code}.meta_description")
                            ->label('SEO tavsif')
                            ->rows(2),
                    ])
                    ->toArray()
                )
                ->toArray()
            );
    }
```

- [ ] **Step 4: Fix table columns to use translation accessor**

Find the `TextColumn::make('title')` in the `table()` method. Replace with:

```php
                TextColumn::make('title')
                    ->label('Sarlavha')
                    ->getStateUsing(fn (Book $r) => $r->trans('title'))
                    ->searchable(query: function ($query, string $search): void {
                        $query->whereHas('translations', fn ($q) =>
                            $q->where('title', 'ilike', "%{$search}%"));
                    })
                    ->limit(40)
                    ->description(fn (Book $r): string => $r->author?->trans('name') ?? ''),
```

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/BookResource.php
git commit -m "feat(i18n): add translation tabs to BookResource form"
```

---

### Task 6.3: Wire `HandlesTranslations` into `CreateBook` and `EditBook` pages

**Files:**
- Modify: `app/Filament/Admin/Resources/BookResource/Pages/CreateBook.php`
- Modify: `app/Filament/Admin/Resources/BookResource/Pages/EditBook.php`

- [ ] **Step 1: Update CreateBook**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookResource\Pages;

use App\Filament\Admin\Resources\BookResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Resources\Pages\CreateRecord;

class CreateBook extends CreateRecord
{
    use HandlesTranslations;

    protected static string $resource = BookResource::class;
}
```

- [ ] **Step 2: Update EditBook**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookResource\Pages;

use App\Filament\Admin\Resources\BookResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBook extends EditRecord
{
    use HandlesTranslations;

    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
```

- [ ] **Step 3: Smoke test**

Open `/admin/books/create`, see Tabs for each active language. Fill Uzbek title (required), leave Russian empty. Save. Open the record in Edit — confirm Uzbek tab pre-fills, Russian tab is empty. Fill Russian, save. Verify `book_translations` table has two rows.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Admin/Resources/BookResource/Pages/
git commit -m "feat(i18n): wire HandlesTranslations into Book Create/Edit pages"
```

---

### Task 6.4: Repeat for `AuthorResource`, `CategoryResource`, `PublisherResource`

**Files:**
- Modify: `app/Filament/Admin/Resources/AuthorResource.php`
- Modify: `app/Filament/Admin/Resources/AuthorResource/Pages/CreateAuthor.php`
- Modify: `app/Filament/Admin/Resources/AuthorResource/Pages/EditAuthor.php`
- Modify: `app/Filament/Admin/Resources/CategoryResource.php`
- Modify: `app/Filament/Admin/Resources/CategoryResource/Pages/CreateCategory.php`
- Modify: `app/Filament/Admin/Resources/CategoryResource/Pages/EditCategory.php`
- Modify: `app/Filament/Admin/Resources/PublisherResource.php`
- Modify: `app/Filament/Admin/Resources/PublisherResource/Pages/CreatePublisher.php`
- Modify: `app/Filament/Admin/Resources/PublisherResource/Pages/EditPublisher.php`

For each resource, apply the same pattern as `BookResource`:

- [ ] **Step 1: AuthorResource — add `translationTabs()` helper**

Inside `AuthorResource`, add the helper (translatable fields: `name`, `bio`, `slug`):

```php
    protected static function translationTabs(): \Filament\Schemas\Components\Tabs
    {
        $tenantId = auth()->user()?->tenant_id;
        $languages = \App\Domain\Localization\Models\TenantLanguage::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return \Filament\Schemas\Components\Tabs::make('Tarjimalar')
            ->tabs(
                $languages->map(fn ($lang) => \Filament\Schemas\Components\Tabs\Tab::make($lang->native_name)
                    ->icon('heroicon-o-language')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make("translations.{$lang->code}.name")
                            ->label('Ism')->required($lang->is_default)->maxLength(255),
                        \Filament\Forms\Components\Textarea::make("translations.{$lang->code}.bio")
                            ->label('Biografiya')->rows(4),
                        \Filament\Forms\Components\TextInput::make("translations.{$lang->code}.slug")
                            ->label('Slug')->maxLength(255),
                    ])
                    ->toArray()
                )
                ->toArray()
            );
    }
```

Replace any `TextInput::make('name')` / `Textarea::make('bio')` in the form schema with `static::translationTabs()`.

Update the table `TextColumn::make('name')` → `->getStateUsing(fn ($r) => $r->trans('name'))` with `whereHas('translations', ...)` search.

- [ ] **Step 2: AuthorResource pages**

```php
// CreateAuthor.php
use App\Filament\Concerns\HandlesTranslations;
class CreateAuthor extends CreateRecord { use HandlesTranslations; /* ... */ }

// EditAuthor.php — same
```

- [ ] **Step 3: CategoryResource**

Translatable fields: `name`, `description`, `slug`. Same pattern:

```php
\Filament\Forms\Components\TextInput::make("translations.{$lang->code}.name")
    ->label('Nomi')->required($lang->is_default),
\Filament\Forms\Components\Textarea::make("translations.{$lang->code}.description")
    ->label('Tavsif')->rows(3),
\Filament\Forms\Components\TextInput::make("translations.{$lang->code}.slug")
    ->label('Slug'),
```

Update Create/Edit pages with `use HandlesTranslations;`.

- [ ] **Step 4: PublisherResource**

Translatable fields: `name`, `description`, `slug`. Same pattern as CategoryResource.

- [ ] **Step 5: Smoke test each resource**

Open each resource in admin: `/admin/authors/create`, `/admin/categories/create`, `/admin/publishers/create`. Create a record with translations in two languages. Edit it. Confirm both tabs pre-fill correctly.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Admin/Resources/AuthorResource* app/Filament/Admin/Resources/CategoryResource* app/Filament/Admin/Resources/PublisherResource*
git commit -m "feat(i18n): add translation tabs to Author/Category/Publisher Filament resources"
```

---

## Stage 7 — UI strings (lang files)

### Task 7.1: Create `lang/uz.json`, `ru.json`, `en.json`

**Files:**
- Create: `lang/uz.json`
- Create: `lang/ru.json`
- Create: `lang/en.json`

- [ ] **Step 1: Write uz.json**

```json
{
  "home": "Bosh sahifa",
  "catalog": "Katalog",
  "audio_books": "Audio kitoblar",
  "news": "Yangiliklar",
  "events": "Tadbirlar",
  "about": "Biz haqimizda",
  "login": "Kirish",
  "logout": "Chiqish",
  "register": "Ro'yxatdan o'tish",
  "search.placeholder": "Kitob, muallif yoki janr bo'yicha qidirish",
  "book.read": "O'qish",
  "book.download": "Yuklab olish",
  "book.author": "Muallif",
  "book.publisher": "Nashriyot",
  "book.category": "Kategoriya",
  "book.pages": "Sahifalar",
  "book.language": "Til",
  "book.published_at": "Nashr sanasi",
  "book.add_to_favorites": "Sevimlilarga qo'shish",
  "common.loading": "Yuklanmoqda...",
  "common.error": "Xatolik yuz berdi",
  "common.retry": "Qaytadan urinish",
  "common.no_results": "Natijalar topilmadi"
}
```

- [ ] **Step 2: Write ru.json**

```json
{
  "home": "Главная",
  "catalog": "Каталог",
  "audio_books": "Аудиокниги",
  "news": "Новости",
  "events": "Мероприятия",
  "about": "О нас",
  "login": "Войти",
  "logout": "Выйти",
  "register": "Регистрация",
  "search.placeholder": "Поиск книги, автора или жанра",
  "book.read": "Читать",
  "book.download": "Скачать",
  "book.author": "Автор",
  "book.publisher": "Издательство",
  "book.category": "Категория",
  "book.pages": "Страниц",
  "book.language": "Язык",
  "book.published_at": "Дата публикации",
  "book.add_to_favorites": "В избранное",
  "common.loading": "Загрузка...",
  "common.error": "Произошла ошибка",
  "common.retry": "Повторить",
  "common.no_results": "Ничего не найдено"
}
```

- [ ] **Step 3: Write en.json**

```json
{
  "home": "Home",
  "catalog": "Catalog",
  "audio_books": "Audiobooks",
  "news": "News",
  "events": "Events",
  "about": "About",
  "login": "Login",
  "logout": "Logout",
  "register": "Register",
  "search.placeholder": "Search by book, author or genre",
  "book.read": "Read",
  "book.download": "Download",
  "book.author": "Author",
  "book.publisher": "Publisher",
  "book.category": "Category",
  "book.pages": "Pages",
  "book.language": "Language",
  "book.published_at": "Published",
  "book.add_to_favorites": "Add to favorites",
  "common.loading": "Loading...",
  "common.error": "An error occurred",
  "common.retry": "Retry",
  "common.no_results": "No results found"
}
```

- [ ] **Step 4: Smoke test endpoint**

`curl -H "X-Tenant-ID: 1" http://127.0.0.1:8000/api/v1/translations/ru | jq`
Expected: JSON with `locale: "ru"` and the full translations map.

- [ ] **Step 5: Commit**

```bash
git add lang/uz.json lang/ru.json lang/en.json
git commit -m "feat(i18n): add frontend UI strings for uz/ru/en"
```

---

### Task 7.2: Translated validation messages

**Files:**
- Create or modify: `lang/ru/validation.php`
- Create or modify: `lang/en/validation.php`

- [ ] **Step 1: Generate base validation files**

Run: `php artisan lang:publish`
Expected: `lang/en/validation.php` etc. created.

- [ ] **Step 2: Copy and translate ru**

If `lang/ru/validation.php` doesn't exist, copy `lang/en/validation.php` to `lang/ru/validation.php` and translate the top-level message strings. Minimum set to translate:

```php
'required'        => 'Поле :attribute обязательно для заполнения.',
'email'           => 'Поле :attribute должно содержать корректный email.',
'min.string'      => 'Поле :attribute должно содержать минимум :min символов.',
'max.string'      => 'Поле :attribute не должно превышать :max символов.',
'unique'          => 'Значение поля :attribute уже занято.',
'confirmed'       => 'Поле :attribute не совпадает с подтверждением.',
```

- [ ] **Step 3: Verify Laravel uses the right file based on app locale**

Run: `php artisan tinker --execute="app()->setLocale('ru'); echo __('validation.required', ['attribute' => 'email']);"`
Expected: Russian translation.

- [ ] **Step 4: Commit**

```bash
git add lang/
git commit -m "feat(i18n): publish and translate validation messages for ru/en"
```

---

## Stage 8 — Tests

> **Note:** If `tests/` directory doesn't yet exist in the project, run `composer require pestphp/pest --dev` and `./vendor/bin/pest --init` first. Pest is already in `composer.json`.

### Task 8.1: Test scaffolding (if needed)

**Files:**
- Create: `tests/Pest.php` (only if missing)
- Create: `tests/TestCase.php` (only if missing)
- Create: `phpunit.xml` (only if missing)

- [ ] **Step 1: Initialize Pest**

If `phpunit.xml` is missing, run: `./vendor/bin/pest --init`
This creates `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php`.

- [ ] **Step 2: Configure test DB**

In `phpunit.xml`, add:

```xml
        <env name="DB_CONNECTION" value="pgsql"/>
        <env name="DB_DATABASE" value="kutubxona_test"/>
        <env name="APP_ENV" value="testing"/>
        <env name="CACHE_DRIVER" value="array"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
```

Create the test database: `createdb kutubxona_test` (or use psql).

- [ ] **Step 3: Run baseline test**

Run: `./vendor/bin/pest`
Expected: at least the framework runs. If there's an example test, it should pass.

- [ ] **Step 4: Commit (only if you created scaffolding)**

```bash
git add phpunit.xml tests/Pest.php tests/TestCase.php
git commit -m "test: initialize Pest scaffolding for i18n tests"
```

---

### Task 8.2: `HasTranslationsTraitTest`

**Files:**
- Create: `tests/Unit/Localization/HasTranslationsTraitTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookTranslation;
use App\Domain\Localization\Models\TenantLanguage;
use App\Domain\Tenant\Models\Tenant;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Test Tenant',
        'slug' => 'test',
        'status' => \App\Domain\Tenant\Enums\TenantStatus::Active,
        'storage_quota' => 0, 'storage_used' => 0, 'max_users' => 0, 'max_books' => 0,
    ]);
    TenantLanguage::create([
        'tenant_id' => $this->tenant->id, 'code' => 'uz', 'name' => 'Uzbek',
        'native_name' => "O'zbekcha", 'is_default' => true, 'is_active' => true,
    ]);
    TenantLanguage::create([
        'tenant_id' => $this->tenant->id, 'code' => 'ru', 'name' => 'Russian',
        'native_name' => 'Русский', 'is_default' => false, 'is_active' => true,
    ]);
    app()->instance('tenant', $this->tenant);
});

test('magic accessor returns current-locale translation', function (): void {
    $book = Book::create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book->id, 'locale' => 'uz',
        'title' => 'Shum bola', 'slug' => 'shum-bola',
    ]);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book->id, 'locale' => 'ru',
        'title' => 'Шум бола', 'slug' => 'shum-bola-ru',
    ]);

    app()->setLocale('uz');
    expect($book->fresh()->title)->toBe('Shum bola');

    app()->setLocale('ru');
    expect($book->fresh()->title)->toBe('Шум бола');
});

test('falls back to tenant default locale when current is missing', function (): void {
    $book = Book::create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book->id, 'locale' => 'uz',
        'title' => 'Faqat o\'zbekcha', 'slug' => 'faqat-uz',
    ]);

    app()->setLocale('ru');
    expect($book->fresh()->title)->toBe("Faqat o'zbekcha");
});

test('trans() with explicit locale ignores app locale', function (): void {
    $book = Book::create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book->id, 'locale' => 'uz',
        'title' => 'UZ title', 'slug' => 'uz-title',
    ]);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book->id, 'locale' => 'ru',
        'title' => 'RU title', 'slug' => 'ru-title',
    ]);

    app()->setLocale('uz');
    expect($book->fresh()->trans('title', 'ru'))->toBe('RU title');
});
```

- [ ] **Step 2: Run test**

Run: `./vendor/bin/pest tests/Unit/Localization/HasTranslationsTraitTest.php`
Expected: 3 passing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Localization/HasTranslationsTraitTest.php
git commit -m "test(i18n): cover HasTranslations magic accessor and fallback"
```

---

### Task 8.3: `LocaleResolutionTest`

**Files:**
- Create: `tests/Feature/Localization/LocaleResolutionTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Domain\Localization\Models\TenantLanguage;
use App\Domain\Tenant\Models\Tenant;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Test', 'slug' => 'test',
        'status' => \App\Domain\Tenant\Enums\TenantStatus::Active,
        'storage_quota' => 0, 'storage_used' => 0, 'max_users' => 0, 'max_books' => 0,
    ]);
    foreach (['uz' => true, 'ru' => false, 'en' => false] as $code => $isDefault) {
        TenantLanguage::create([
            'tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $code,
            'native_name' => $code, 'is_default' => $isDefault, 'is_active' => true,
        ]);
    }
});

test('query param wins over header', function (): void {
    $response = $this->withHeaders([
        'X-Tenant-ID' => $this->tenant->id,
        'X-Locale' => 'ru',
    ])->get('/api/v1/languages?lang=en');

    $response->assertOk()->assertHeader('Content-Language', 'en');
});

test('X-Locale header wins over Accept-Language', function (): void {
    $response = $this->withHeaders([
        'X-Tenant-ID' => $this->tenant->id,
        'X-Locale' => 'ru',
        'Accept-Language' => 'en-US,en;q=0.9',
    ])->get('/api/v1/languages');

    $response->assertHeader('Content-Language', 'ru');
});

test('falls back to tenant default when requested locale is inactive', function (): void {
    TenantLanguage::where('code', 'en')->update(['is_active' => false]);

    $response = $this->withHeaders([
        'X-Tenant-ID' => $this->tenant->id,
        'X-Locale' => 'en',
    ])->get('/api/v1/languages');

    $response->assertHeader('Content-Language', 'uz');
});

test('uses Accept-Language when no explicit locale', function (): void {
    $response = $this->withHeaders([
        'X-Tenant-ID' => $this->tenant->id,
        'Accept-Language' => 'ru-RU,ru;q=0.9',
    ])->get('/api/v1/languages');

    $response->assertHeader('Content-Language', 'ru');
});
```

- [ ] **Step 2: Run test**

Run: `./vendor/bin/pest tests/Feature/Localization/LocaleResolutionTest.php`
Expected: 4 passing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Localization/LocaleResolutionTest.php
git commit -m "test(i18n): cover locale resolution priority chain and fallback"
```

---

### Task 8.4: `TenantLanguageManagementTest`

**Files:**
- Create: `tests/Feature/Localization/TenantLanguageManagementTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Domain\Localization\Models\TenantLanguage;
use App\Domain\Tenant\Models\Tenant;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('observer flips other defaults when a new default is set', function (): void {
    $tenant = Tenant::create([
        'name' => 'T', 'slug' => 't',
        'status' => \App\Domain\Tenant\Enums\TenantStatus::Active,
        'storage_quota' => 0, 'storage_used' => 0, 'max_users' => 0, 'max_books' => 0,
    ]);

    $uz = TenantLanguage::create([
        'tenant_id' => $tenant->id, 'code' => 'uz', 'name' => 'Uzbek',
        'native_name' => "O'zbekcha", 'is_default' => true, 'is_active' => true,
    ]);
    $ru = TenantLanguage::create([
        'tenant_id' => $tenant->id, 'code' => 'ru', 'name' => 'Russian',
        'native_name' => 'Русский', 'is_default' => false, 'is_active' => true,
    ]);

    $ru->update(['is_default' => true]);

    expect($uz->fresh()->is_default)->toBeFalse();
    expect($ru->fresh()->is_default)->toBeTrue();
});

test('deleting default promotes another active language', function (): void {
    $tenant = Tenant::create([
        'name' => 'T', 'slug' => 't',
        'status' => \App\Domain\Tenant\Enums\TenantStatus::Active,
        'storage_quota' => 0, 'storage_used' => 0, 'max_users' => 0, 'max_books' => 0,
    ]);

    $uz = TenantLanguage::create([
        'tenant_id' => $tenant->id, 'code' => 'uz', 'name' => 'Uzbek',
        'native_name' => "O'zbekcha", 'is_default' => true, 'is_active' => true,
    ]);
    $ru = TenantLanguage::create([
        'tenant_id' => $tenant->id, 'code' => 'ru', 'name' => 'Russian',
        'native_name' => 'Русский', 'is_default' => false, 'is_active' => true, 'sort_order' => 1,
    ]);

    $uz->delete();

    expect($ru->fresh()->is_default)->toBeTrue();
});
```

- [ ] **Step 2: Run test**

Run: `./vendor/bin/pest tests/Feature/Localization/TenantLanguageManagementTest.php`
Expected: 2 passing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Localization/TenantLanguageManagementTest.php
git commit -m "test(i18n): cover TenantLanguage observer default invariant"
```

---

### Task 8.5: `ApiBookListingLocaleTest`

**Files:**
- Create: `tests/Feature/Localization/ApiBookListingLocaleTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookTranslation;
use App\Domain\Localization\Models\TenantLanguage;
use App\Domain\Tenant\Models\Tenant;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'T', 'slug' => 't',
        'status' => \App\Domain\Tenant\Enums\TenantStatus::Active,
        'storage_quota' => 0, 'storage_used' => 0, 'max_users' => 0, 'max_books' => 0,
    ]);
    TenantLanguage::create([
        'tenant_id' => $this->tenant->id, 'code' => 'uz', 'name' => 'Uzbek',
        'native_name' => "O'zbekcha", 'is_default' => true, 'is_active' => true,
    ]);
    TenantLanguage::create([
        'tenant_id' => $this->tenant->id, 'code' => 'ru', 'name' => 'Russian',
        'native_name' => 'Русский', 'is_default' => false, 'is_active' => true,
    ]);

    $this->book = Book::create([
        'tenant_id' => $this->tenant->id, 'status' => 'published',
    ]);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $this->book->id,
        'locale' => 'uz', 'title' => 'Shum bola', 'slug' => 'shum-bola',
    ]);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $this->book->id,
        'locale' => 'ru', 'title' => 'Шум бола', 'slug' => 'shum-bola-ru',
    ]);
});

test('book listing returns Uzbek title for uz locale', function (): void {
    $response = $this->withHeaders([
        'X-Tenant-ID' => $this->tenant->id,
        'X-Locale' => 'uz',
    ])->get('/api/v1/books');

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toContain('Shum bola');
});

test('book listing returns Russian title for ru locale', function (): void {
    $response = $this->withHeaders([
        'X-Tenant-ID' => $this->tenant->id,
        'X-Locale' => 'ru',
    ])->get('/api/v1/books');

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toContain('Шум бола');
});

test('book listing falls back to default when locale translation missing', function (): void {
    BookTranslation::where('book_id', $this->book->id)
        ->where('locale', 'ru')
        ->delete();

    $response = $this->withHeaders([
        'X-Tenant-ID' => $this->tenant->id,
        'X-Locale' => 'ru',
    ])->get('/api/v1/books');

    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toContain('Shum bola');
});
```

- [ ] **Step 2: Run test**

Run: `./vendor/bin/pest tests/Feature/Localization/ApiBookListingLocaleTest.php`
Expected: 3 passing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Localization/ApiBookListingLocaleTest.php
git commit -m "test(i18n): cover API book listing emits correct locale with fallback"
```

---

### Task 8.6: `SlugUniquenessTest`

**Files:**
- Create: `tests/Feature/Localization/SlugUniquenessTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookTranslation;
use App\Domain\Tenant\Models\Tenant;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'T', 'slug' => 't',
        'status' => \App\Domain\Tenant\Enums\TenantStatus::Active,
        'storage_quota' => 0, 'storage_used' => 0, 'max_users' => 0, 'max_books' => 0,
    ]);
    app()->instance('tenant', $this->tenant);
});

test('same slug allowed across different locales', function (): void {
    $book = Book::create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);

    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book->id,
        'locale' => 'uz', 'title' => 'Title', 'slug' => 'same-slug',
    ]);
    BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book->id,
        'locale' => 'ru', 'title' => 'Title', 'slug' => 'same-slug',
    ]);

    expect(BookTranslation::where('slug', 'same-slug')->count())->toBe(2);
});

test('duplicate slug within same locale gets unique suffix', function (): void {
    $book1 = Book::create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    $book2 = Book::create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);

    $t1 = BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book1->id,
        'locale' => 'uz', 'title' => 'Same Title',
    ]);
    $t2 = BookTranslation::create([
        'tenant_id' => $this->tenant->id, 'book_id' => $book2->id,
        'locale' => 'uz', 'title' => 'Same Title',
    ]);

    expect($t1->slug)->toBe('same-title');
    expect($t2->slug)->toBe('same-title-1');
});
```

- [ ] **Step 2: Run test**

Run: `./vendor/bin/pest tests/Feature/Localization/SlugUniquenessTest.php`
Expected: 2 passing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Localization/SlugUniquenessTest.php
git commit -m "test(i18n): cover slug uniqueness across and within locales"
```

---

### Task 8.7: `TranslationsEndpointTest`

**Files:**
- Create: `tests/Feature/Localization/TranslationsEndpointTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Domain\Tenant\Models\Tenant;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'T', 'slug' => 't',
        'status' => \App\Domain\Tenant\Enums\TenantStatus::Active,
        'storage_quota' => 0, 'storage_used' => 0, 'max_users' => 0, 'max_books' => 0,
    ]);
});

test('returns JSON translations for known locale', function (): void {
    $response = $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
        ->get('/api/v1/translations/ru');

    $response->assertOk()
        ->assertJsonPath('locale', 'ru')
        ->assertJsonStructure(['locale', 'translations']);
});

test('returns 404 for unknown locale', function (): void {
    $this->withHeaders(['X-Tenant-ID' => $this->tenant->id])
        ->get('/api/v1/translations/xyz')
        ->assertNotFound();
});
```

- [ ] **Step 2: Run test**

Run: `./vendor/bin/pest tests/Feature/Localization/TranslationsEndpointTest.php`
Expected: 2 passing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Localization/TranslationsEndpointTest.php
git commit -m "test(i18n): cover translations endpoint contract"
```

---

### Task 8.8: Full test suite green

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/pest`
Expected: all tests in `tests/Feature/Localization/` and `tests/Unit/Localization/` pass.

If something else breaks (existing tests that used `Book::title` etc.), fix them — most likely the existing test data needs translation rows now.

- [ ] **Step 2: Final cleanup commit (if any fixups)**

```bash
git add -p
git commit -m "test: fix existing tests broken by translation refactor"
```

---

## Final verification checklist

- [ ] All migrations run cleanly on a fresh DB (`php artisan migrate:fresh` works end-to-end)
- [ ] All migrations roll back without errors (`php artisan migrate:rollback --step=9` works)
- [ ] `/admin/tenant-languages` CRUD works in browser
- [ ] Creating a book in admin with two-language tabs persists both translations
- [ ] `GET /api/v1/languages` returns the active language list
- [ ] `GET /api/v1/translations/uz` returns the UI string map
- [ ] `GET /api/v1/books` with `X-Locale: ru` returns Russian titles where available, Uzbek titles otherwise
- [ ] `./vendor/bin/pest` is fully green
- [ ] Image upload still works (sanity check that we didn't break unrelated flows)

---

## Production deploy commands

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
php artisan up
```
