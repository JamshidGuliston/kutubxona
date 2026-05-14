# Multi-Language Support — Design Spec

**Date:** 2026-05-14
**Author:** Brainstorming session
**Status:** Approved, ready for implementation planning

## Goal

Enable each tenant to define their own set of languages and serve book content (metadata) and frontend UI strings in any of those languages. Each book stays as a single record but its translatable fields (title, subtitle, description, slug, etc.) live in a `book_translations` table keyed by locale.

## Scope

In scope:
- Per-tenant configurable language list (CRUD via admin panel)
- Database translation for: `Book`, `Author`, `Category`, `Publisher`, `Tag`
- Frontend UI strings via Laravel lang files (`lang/uz.json`, `ru.json`, `en.json`)
- Locale resolution middleware (query → header → cookie → Accept-Language → tenant default)
- Filament admin: language tabs in each translatable resource form
- Fallback to tenant default locale when current locale translation is missing
- Per-locale SEO-friendly slugs
- Backfill migration for existing data

Out of scope (future work):
- Auto-translate via AI / DeepL
- Per-tenant UI string overrides
- Locale-aware Scout/search indexing optimization
- Translation status badges in listings
- Bulk CSV translation import/export
- Multiple file versions per language (one PDF per book stays the rule)

## Architecture overview

Translation lives in separate tables (one per translatable entity), referenced by `(translatable_id, locale)`. Parent tables keep only language-independent fields. A shared `HasTranslations` trait provides:

- `$model->title` — magic accessor returning current locale value with fallback
- `$model->trans('title', 'ru')` — explicit accessor for a specific locale
- `$model->translations` — relation to all translations
- `$model->translation` — eager-loadable current-locale relation

The locale is resolved once per request by `ResolveLocaleMiddleware`, then `app()->setLocale($locale)` is called and accessors read from it.

## Data model

### New table: `tenant_languages`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk → `tenants.id`, cascade delete | |
| `code` | string(10) | `'uz'`, `'ru'`, `'en'`, `'uz-cyrl'`, etc. |
| `name` | string | admin-facing display name, e.g. `"Russian"` |
| `native_name` | string | frontend selector display, e.g. `"Русский"` |
| `flag_emoji` | string(10) nullable | e.g. `"🇺🇿"` |
| `is_default` | boolean | exactly one row per tenant has `true` |
| `is_active` | boolean | inactive languages hidden from frontend |
| `sort_order` | unsigned int | display order |
| `created_at` / `updated_at` | timestamps | |

Constraints:
- `UNIQUE(tenant_id, code)`
- `INDEX(tenant_id, is_active)`

Invariant: exactly one row per `tenant_id` has `is_default = true`. Enforced by:
- A model observer that flips other rows to `false` when a new default is set
- A seeder that creates a default `'uz'` row for every existing tenant

### New table: `book_translations`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk → `tenants.id` | denormalized for `TenantScope` and indexing |
| `book_id` | fk → `books.id`, cascade delete | |
| `locale` | string(10) | |
| `title` | string(500) | |
| `subtitle` | string(500) nullable | |
| `description` | text nullable | |
| `slug` | string(500) | per-locale SEO slug |
| `meta_title` | string nullable | |
| `meta_description` | string nullable | |
| `created_at` / `updated_at` | timestamps | |

Constraints:
- `UNIQUE(book_id, locale)`
- `UNIQUE(tenant_id, locale, slug)` — slug uniqueness scoped to tenant + locale
- `INDEX(tenant_id, locale)`
- Postgres `tsvector` index on `title || description` for full-text search per locale (added in a follow-up migration)

### New tables: `author_translations`, `category_translations`, `publisher_translations`, `tag_translations`

Same pattern as `book_translations`. Translatable columns per entity:

| entity | translatable columns |
|---|---|
| `author_translations` | `name`, `bio`, `slug` |
| `category_translations` | `name`, `description`, `slug` |
| `publisher_translations` | `name`, `description`, `slug` |
| `tag_translations` | `name`, `slug` |

All share: `id`, `tenant_id`, `<parent>_id`, `locale`, timestamps, `UNIQUE(parent_id, locale)`, `UNIQUE(tenant_id, locale, slug)`, `INDEX(tenant_id, locale)`.

### Changes to existing tables

`books` — drop columns: `title`, `subtitle`, `description`, `slug`, `language`. Drop the corresponding `UNIQUE(tenant_id, slug)` and `INDEX(language)`. Drop `FULLTEXT(title, description)`.

`authors` — drop: `name`, `slug`, `bio`. Drop `UNIQUE(tenant_id, slug)`, `FULLTEXT(name)`.

`categories` — drop: `name`, `slug`, `description`. Drop `UNIQUE(tenant_id, slug)`.

`publishers` — drop: `name`, `slug`, `description`. Drop `UNIQUE(tenant_id, slug)`.

`tags` — drop: `name`, `slug`. Drop `UNIQUE(tenant_id, slug)`.

## Domain layer

### New directory: `app/Domain/Localization/`

```
app/Domain/Localization/
  Models/
    TenantLanguage.php
  Contracts/
    HasTranslations.php       # interface
  Traits/
    HasTranslations.php       # trait
```

### `HasTranslations` contract

```php
interface HasTranslations {
    public function translations(): HasMany;
    public function translation(): HasOne;
    public function trans(string $field, ?string $locale = null): ?string;
    public function getTranslatableFields(): array;
    public function getTranslationModelClass(): string;
}
```

### `HasTranslations` trait — shared behavior

- `translations()` — `hasMany(<entity>Translation::class)`
- `translation()` — `hasOne(...)->where('locale', app()->getLocale())`
- `trans($field, $locale = null)` — looks up `$locale`, falls back to `$this->tenant->default_locale`
- Overrides `getAttribute($key)` to delegate to `trans($key)` when `$key` is in `getTranslatableFields()` and not already a real column

### Translation models (5 total)

Each follows the same shape (example: `BookTranslation`):

```php
final class BookTranslation extends Model {
    protected $fillable = ['tenant_id', 'book_id', 'locale', 'title',
                            'subtitle', 'description', 'slug',
                            'meta_title', 'meta_description'];

    protected static function booted(): void {
        static::addGlobalScope(new TenantScope());
        static::creating(function (self $t): void {
            if (empty($t->slug) && !empty($t->title)) {
                $t->slug = static::generateUniqueSlug($t);
            }
        });
    }

    protected static function generateUniqueSlug(self $t): string {
        $base = Str::slug($t->title);
        $slug = $base;
        $i = 1;
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

    public function book(): BelongsTo { return $this->belongsTo(Book::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
```

Slug generation respects the per-tenant + per-locale uniqueness constraint.

### Updates to existing models

`Book` — add `use HasTranslations`, implement `getTranslatableFields(): ['title', 'subtitle', 'description', 'slug', 'meta_title', 'meta_description']`, remove the columns from `$fillable`, update `toSearchableArray()` to source from translations (current locale), remove `scopeByLanguage()` (no longer applies), update `coverUrl`/`thumbnailUrl` accessors (unchanged — they read non-translatable columns).

`Author`, `Category`, `Publisher`, `Tag` — same pattern.

`Tenant` — add:
- `languages(): HasMany` — all `TenantLanguage` rows
- `activeLanguages(): HasMany` — `where('is_active', true)`
- `defaultLanguage(): HasOne` — `where('is_default', true)`
- `getDefaultLocaleAttribute(): string` — returns `defaultLanguage->code ?? config('app.locale')`

## Locale resolution

### `ResolveLocaleMiddleware`

Location: `app/Interfaces/Http/Middleware/ResolveLocaleMiddleware.php`

Resolution priority:
1. `?lang=` query parameter
2. `X-Locale` request header
3. `locale` cookie
4. `Accept-Language` header (parsed via Symfony's `AcceptHeader::fromString()` — already available in Laravel)
5. `tenant->default_locale`
6. `config('app.locale')` (final fallback, `'uz'`)

Validation: the resolved locale must exist as `is_active = true` in the current tenant's `tenant_languages`. If not, fall back silently to tenant default.

Side effects:
- `app()->setLocale($locale)`
- Adds `Content-Language: <locale>` to response

### Middleware ordering

`TenantMiddleware` must run **before** `ResolveLocaleMiddleware` (the latter needs the resolved tenant for validation). Register in `bootstrap/app.php` or directly in route groups:

```php
Route::middleware(['tenant', 'locale'])->group(...);
```

### Public endpoints

`GET /api/languages` — returns the current tenant's active languages. Cached 5 minutes per tenant.

Response:
```json
{
  "default_locale": "uz",
  "languages": [
    {"code": "uz", "name": "O'zbekcha", "native_name": "O'zbekcha", "flag": "🇺🇿", "is_default": true},
    {"code": "ru", "name": "Russian",   "native_name": "Русский",   "flag": "🇷🇺", "is_default": false}
  ]
}
```

`GET /api/translations/{locale}` — returns UI strings for the requested locale (Laravel lang files). Cached 1 hour.

## API resources

`BookResource`, `AuthorResource`, `CategoryResource`, `PublisherResource`, `TagResource` — use the magic accessor (`$this->title`) so each returned record is already in the resolved locale.

Optional `?with_translations=1` query parameter returns the full `translations` array keyed by locale (used by the admin panel).

The shape of `BookResource` for normal frontend requests stays compatible with the current contract — the `title` / `description` keys are present, only their source changes. Existing frontend code keeps working without changes (it only gets new behavior when it starts sending a different locale).

## Filament admin panel

### New resource: `TenantLanguageResource`

Location: `app/Filament/Admin/Resources/TenantLanguageResource.php`

Visible only to `tenant_admin` (policy gate). Lives under a "Sozlamalar" navigation group.

Form fields: `code`, `name`, `native_name`, `flag_emoji`, `is_default` (Toggle), `is_active` (Toggle), `sort_order`.

Observer logic: when a row is saved with `is_default = true`, set all other rows of the same tenant to `is_default = false`.

### Translation tabs in existing resources

Affected resources: `BookResource`, `AuthorResource`, `CategoryResource`, `PublisherResource`, (TagResource — to be added if it doesn't exist yet).

Form structure: language-independent fields stay in their current sections (e.g. "Asosiy" for `author_id`, `category_id`, `status`, `is_featured`). Translatable fields move into a new `Tabs` component with one tab per active tenant language.

Tab content for `BookResource`:
- `translations.{locale}.title` (required if `is_default` for that locale)
- `translations.{locale}.subtitle`
- `translations.{locale}.description` (Textarea)
- `translations.{locale}.slug` (auto-generated from title if blank)
- `translations.{locale}.meta_title`
- `translations.{locale}.meta_description`

### Shared trait: `HandlesTranslations`

Location: `app/Filament/Concerns/HandlesTranslations.php`

Lifecycle hooks applied to `Create*` and `Edit*` page classes:

- `mutateFormDataBeforeSave($data)` — extracts `$data['translations']` into a local property, removes it from the main data array
- `afterSave()` — iterates the extracted translations array and calls `$this->record->translations()->updateOrCreate(['locale' => $locale], $fields)`
- `fillForm()` (on Edit pages) — pre-fills the `translations.{locale}.*` form fields from existing translation records

Skips a locale entirely if its `title`/`name` field is blank (no orphan empty translations).

### Table columns

In listings, translatable columns (e.g. `BookResource::table` `title` column) use `->getStateUsing(fn ($r) => $r->trans('title'))` to show the tenant default locale's value.

Search across translations:
```php
->searchable(query: function (Builder $query, string $search): Builder {
    return $query->whereHas('translations', fn ($q) =>
        $q->where('title', 'ilike', "%{$search}%"));
})
```

## UI strings (lang files)

Location: `lang/uz.json`, `lang/ru.json`, `lang/en.json`.

Format: flat JSON with dot-notation keys for namespacing.

```json
{
  "home": "Bosh sahifa",
  "catalog": "Katalog",
  "audio_books": "Audio kitoblar",
  "search.placeholder": "Kitob, muallif yoki janr bo'yicha qidirish",
  "book.read": "O'qish",
  "book.download": "Yuklab olish"
}
```

Validation messages: standard Laravel translation files at `lang/<locale>/validation.php`.

### Endpoint for SPA consumption

`GET /api/translations/{locale}` — returns all key/value pairs from the JSON file for the requested locale. Cached 1 hour.

```php
Route::get('/api/translations/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['uz', 'ru', 'en'], true), 404);
    return Cache::remember("translations.{$locale}", 3600,
        fn () => json_decode(file_get_contents(lang_path("{$locale}.json")), true)
    );
});
```

Cache is cleared by `php artisan cache:clear` on deploy.

## Migration strategy

Migrations execute in this order (each is a separate file):

1. `create_tenant_languages_table`
2. `seed_default_tenant_languages` — a data-only migration (not a `database/seeders/` seeder) that inserts one `'uz'` row per existing tenant with `is_default = true`. Runs in production via `php artisan migrate`.
3. `create_book_translations_table`
4. `create_author_translations_table`
5. `create_category_translations_table`
6. `create_publisher_translations_table`
7. `create_tag_translations_table`
8. `backfill_translations_from_existing_columns` — copies current `books.title` etc. into translation tables under the row's existing `language` (default `'uz'`)
9. `drop_translated_columns_from_main_tables` — drops the columns listed in the "Changes to existing tables" section

### Backfill migration logic

Run inside `DB::transaction()`. For each translatable entity:

```php
DB::table('books')->orderBy('id')->chunkById(500, function ($books) {
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
            'created_at'  => now(),
            'updated_at'  => now(),
        ];
    }
    DB::table('book_translations')->insert($rows);
});
```

Apply the same pattern to authors, categories, publishers, tags.

### Production deployment

```bash
php artisan down
php artisan migrate --force
php artisan cache:clear
php artisan filament:cache-components
php artisan config:cache
php artisan up
```

### Rollback

`down()` methods restore the dropped columns and copy data back from translation tables (using tenant default locale). Translation tables themselves are dropped last. Rollback is intentionally lossy for non-default-locale data, since that data did not exist before the migration — and the spec assumes rollback is a last resort.

## Testing

Feature tests live under `tests/Feature/Localization/`:

- `LocaleResolutionTest` — middleware picks correct locale across all priority levels; falls back when locale inactive
- `BookTranslationTest` — magic accessor returns current locale; `trans()` returns explicit locale; fallback to tenant default when missing
- `TenantLanguageManagementTest` — CRUD via Filament page (acting as `tenant_admin`); `is_default` uniqueness invariant
- `BookCrudWithTranslationsTest` — admin creates a book with translations in 2 languages, verifies both saved; edit page pre-fills correctly
- `ApiBookListingTest` — `GET /api/books?lang=ru` returns Russian title when available, Uzbek title otherwise
- `TranslationsEndpointTest` — `GET /api/translations/ru` returns valid JSON map
- `SlugUniquenessTest` — slug unique within `(tenant, locale)`, allowed to repeat across locales

Unit tests:
- `HasTranslationsTraitTest` — accessor behavior, fallback chain, magic `getAttribute` interaction with real columns

## Build sequence

Implementation is split into independent stages that can be reviewed and merged separately:

1. **Foundation**
   - `TenantLanguage` model + migration
   - Seeder for default `'uz'` language per tenant
   - `TenantLanguageResource` Filament page (CRUD)
   - Observer enforcing single `is_default` per tenant

2. **Translation infrastructure**
   - `HasTranslations` contract + trait
   - 5 translation models
   - 5 translation table migrations
   - `TenantScope` applied to translation models

3. **Data migration**
   - Backfill migration
   - Drop-columns migration
   - Update existing model code paths that referenced dropped columns (e.g. `toSearchableArray`, `scopeByLanguage`)

4. **Locale resolution**
   - `ResolveLocaleMiddleware`
   - Route registration
   - `GET /api/languages` endpoint

5. **API resources**
   - Update existing resources to read from translations
   - `GET /api/translations/{locale}` endpoint

6. **Admin panel**
   - `HandlesTranslations` trait
   - `BookResource` form with `Tabs` component
   - Same treatment for Author/Category/Publisher/Tag
   - Listing column updates (search across translations)

7. **UI strings**
   - `lang/uz.json`, `ru.json`, `en.json`
   - `lang/<locale>/validation.php`

8. **Tests** — written alongside each stage above (TDD where practical)

Rough estimate: 6–7 working days for full implementation.

## Open questions / deferred decisions

- **Auto-translate** — AI-assisted translation button in admin panel. Defer.
- **Translation completeness badge** — show `🇺🇿✓ 🇷🇺✓ 🇬🇧–` in listings to indicate which locales are complete. Defer.
- **Bulk CSV translation import/export** — defer.
- **Tenant-level UI string overrides** — allow tenants to override specific UI labels for their own branding. Defer.
- **Locale-aware Scout indexing** — separate Scout index per locale, or use Meilisearch's multilingual mode. Defer — measure first.

## Invariants and constraints

- Exactly one `tenant_languages` row per tenant has `is_default = true`.
- Every translatable entity has at least one translation in the tenant default locale (enforced at the form level: default-locale title is `->required()`).
- `(book_id, locale)` is unique. Same for all other translation tables.
- `(tenant_id, locale, slug)` is unique across each translation table.
- `TenantScope` applies to translation models so cross-tenant data leaks are impossible at the ORM layer.
- Frontend never sees translations from an inactive locale (filter applied in `ResolveLocaleMiddleware`).
