# News / CMS Module — Design Spec

**Date:** 2026-05-14
**Status:** Approved, ready for implementation planning

## Goal

Add a full-featured news/articles module per tenant. Admins create localized news articles with rich content, cover images, scheduled publishing, and moderate user comments. Frontend visitors browse the news catalog, read articles in their preferred locale, like, and comment.

## Scope

In scope:
- News article CRUD (title, slug, excerpt, body, cover image, meta SEO fields)
- News categories per tenant
- Full i18n via existing translation pattern (`*_translations` tables + `HasTranslations` trait + `trans()` accessor)
- Tenant scoping via existing `TenantScope`
- Draft/published/archived status, scheduled publishing (`published_at` in future)
- Featured flag for hero slot
- View counter
- User likes (one per user, toggle)
- User comments with one level of nested replies, admin moderation gate
- Filament admin: NewsResource, NewsCategoryResource, NewsCommentResource (moderation)
- Public + authenticated REST API endpoints
- Angular frontend: news list (hero + grid), detail page, like button, comment list/form

Out of scope (future work):
- Multiple levels of comment nesting (1 level only)
- Comment reactions other than the binary like (no emojis/polls)
- Image galleries within body (cover_image only; embedded images via rich-text editor are allowed but no separate gallery model)
- Per-author analytics
- Email notifications for new comments / approvals
- News categories themselves having i18n SEO fields (only `name`, `description`, `slug` localized)
- Bulk import from RSS / external CMS

## Architecture overview

Standalone `App\Domain\News` aggregate. News and news categories follow the same translation pattern proven on Book/Author/Category: parent table holds language-independent columns, `*_translations` table holds per-locale text, the parent model wires `HasTranslations` trait + interface and declares `TRANSLATION_MODEL` / `TRANSLATABLE_FIELDS`.

Comments and likes are children of news, tenant-scoped via the same `TenantScope`. Denormalized counters (`comment_count`, `like_count`) live on `news` to avoid count queries on the catalog list page; an observer keeps them in sync.

API resources call `$this->trans('field')` for translated content so the locale resolution middleware (already shipped) drives output language. Frontend list/detail pages refetch when the locale signal changes (same pattern as `HomeComponent`).

## Data model

### `news_categories`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk → `tenants.id`, cascade | |
| `parent_id` | fk → `news_categories.id`, null on delete | nullable; 1 level of hierarchy allowed |
| `icon` | string nullable | optional Lucide/Heroicon name |
| `color` | char(7) nullable | hex |
| `sort_order` | unsigned int | |
| `is_active` | boolean | |
| timestamps | | |

`INDEX(tenant_id, is_active)`

### `news_category_translations`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk | denormalized for `TenantScope` |
| `news_category_id` | fk, cascade | |
| `locale` | string(10) | |
| `name` | string | |
| `description` | text nullable | |
| `slug` | string | |
| timestamps | | |

`UNIQUE(news_category_id, locale)` · `UNIQUE(tenant_id, locale, slug)` · `INDEX(tenant_id, locale)`

### `news`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk → `tenants.id`, cascade | |
| `news_category_id` | fk → `news_categories.id`, null on delete | nullable |
| `author_id` | fk → `users.id`, null on delete | the staff member who wrote it |
| `cover_image` | string(500) nullable | path on `uploads` disk |
| `thumbnail` | string(500) nullable | auto-derived smaller version |
| `status` | string(20) | `'draft' \| 'published' \| 'archived'` |
| `is_featured` | boolean | hero candidate |
| `view_count` | unsigned int | |
| `like_count` | unsigned int | denormalized |
| `comment_count` | unsigned int | denormalized (approved only) |
| `published_at` | timestamp nullable | NULL until first publish; future date = scheduled |
| `created_by` | fk → users | |
| `updated_by` | fk → users | |
| timestamps + soft deletes | | |

`INDEX(tenant_id, status, published_at)` · `INDEX(tenant_id, is_featured)` · `INDEX(news_category_id)` · `INDEX(published_at)`

A news row is "live" when `status = 'published' AND published_at IS NOT NULL AND published_at <= now()`. The `live()` scope encodes this.

### `news_translations`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk | |
| `news_id` | fk, cascade | |
| `locale` | string(10) | |
| `title` | string(500) | |
| `slug` | string(500) | |
| `excerpt` | text nullable | listing card / SEO description fallback |
| `body` | longtext | sanitized HTML from rich-text editor |
| `meta_title` | string nullable | |
| `meta_description` | text nullable | |
| timestamps | | |

`UNIQUE(news_id, locale)` · `UNIQUE(tenant_id, locale, slug)` · `INDEX(tenant_id, locale)`

### `news_comments`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk, cascade | |
| `news_id` | fk → `news.id`, cascade | |
| `user_id` | fk → `users.id`, cascade | |
| `parent_id` | fk → `news_comments.id`, cascade | nullable; application-enforced to point only at top-level comments (see invariant below) |
| `body` | text | plain text; HTML entities escaped at render time |
| `is_approved` | boolean default false | admin moderation gate |
| timestamps | | |

`INDEX(news_id, is_approved, created_at)` · `INDEX(user_id)`

Invariant: `parent_id` references a top-level comment (whose own `parent_id` is NULL). Enforced by `NewsCommentObserver::creating()` — if `parent_id` is set and the parent already has its own `parent_id`, raise `\InvalidArgumentException`.

### `news_likes`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | fk, cascade | |
| `news_id` | fk, cascade | |
| `user_id` | fk, cascade | |
| `created_at` | timestamp | |

`UNIQUE(news_id, user_id)` · `INDEX(user_id)`

No `updated_at` — likes are immutable; toggle is implemented by `delete()` + `create()`.

## Domain layer

### Directory layout

```
app/Domain/News/
  Enums/
    NewsStatus.php
  Models/
    News.php
    NewsTranslation.php
    NewsCategory.php
    NewsCategoryTranslation.php
    NewsComment.php
    NewsLike.php
  Observers/
    NewsObserver.php
    NewsCommentObserver.php
    NewsLikeObserver.php
```

### `NewsStatus` enum

```php
enum NewsStatus: string {
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string;
    public function color(): string; // for Filament badges
}
```

### `News` model

- Implements `App\Domain\Localization\Contracts\HasTranslations`
- `use HasTranslations, HasFactory, SoftDeletes`
- `const TRANSLATION_MODEL = NewsTranslation::class`
- `const TRANSLATABLE_FIELDS = ['title', 'slug', 'excerpt', 'body', 'meta_title', 'meta_description']`
- `$casts`: `status` → `NewsStatus::class`, `is_featured` → bool, `published_at` → datetime, counters → int
- `booted()`:
  - `addGlobalScope(new TenantScope())`
  - on creating: auto-fill `tenant_id` from `app('tenant')`, `created_by` from `auth()->id()`
  - on updating: auto-fill `updated_by`
- Relations:
  - `tenant()`, `category()` (NewsCategory), `author()` (User)
  - `translations()`, `translation()` from trait
  - `comments()` → HasMany NewsComment
  - `approvedComments()` → comments where `is_approved = true`
  - `likes()` → HasMany NewsLike
- Scopes:
  - `live()` — `status = 'published' AND published_at <= now()`
  - `scheduled()` — `status = 'published' AND published_at > now()`
  - `featured()` — `is_featured = true`
  - `byCategory($categoryId)`
  - `popular()` — order by `view_count` desc
  - `recent()` — order by `published_at` desc
- Methods:
  - `isLive(): bool`
  - `incrementViewCount(): void`
  - `recalculateCounts(): void` — refreshes `like_count` and `comment_count` from related tables
- Accessors:
  - `coverUrl()` → public URL via `uploads` disk
  - `thumbnailUrl()` → same, with fallback to `coverUrl`

### `NewsCategory` model

- Implements `HasTranslations`, with `TRANSLATABLE_FIELDS = ['name', 'description', 'slug']`
- Relations: `tenant()`, `parent()` (self), `children()` (self), `news()` → HasMany News
- Scope: `active()`, `roots()` (where parent_id is null)

### `NewsComment` model

- TenantScope
- Relations: `tenant()`, `news()`, `user()`, `parent()` (self), `replies()` (self, parent_id)
- Scope: `approved()`, `pending()` (where `is_approved = false`)
- `isReply(): bool` — `parent_id !== null`

### `NewsLike` model

- TenantScope
- `$timestamps` — only `created_at` (no `updated_at`)
- Relations: `news()`, `user()`

### Observers

`NewsObserver`:
- `saved()`: if the row transitioned to `status=published` AND `published_at IS NULL`, set `published_at = now()`

`NewsCommentObserver`:
- `creating()`: validate `parent_id` is not itself a reply (1-level constraint)
- `saved()` and `deleted()`: call `$this->news->recalculateCounts()` if approval state changed

`NewsLikeObserver`:
- `created()` and `deleted()`: call `$this->news->recalculateCounts()`

All three observers are registered via `#[ObservedBy([...])]` PHP attribute on the model class.

## Locale resolution

No new middleware. The existing `ResolveLocaleMiddleware` (from i18n rollout) sets `app()->setLocale()` before route handlers run. News resources call `$this->trans('title')`, `$this->trans('excerpt')`, etc. exactly like Book/Author resources do.

## API layer

All endpoints live under the existing v1 tenant-scoped, locale-resolved route group: `Route::prefix('v1')->middleware(['tenant', 'locale', 'tenant.scope'])`.

### Public

| route | controller method | purpose |
|---|---|---|
| `GET /news` | `NewsController@index` | paginated list, filters: `?category=<slug>`, `?search=<q>`, `?page=N`. Only `live()` rows. |
| `GET /news/featured` | `NewsController@featured` | up to 5 featured live rows |
| `GET /news/latest` | `NewsController@latest` | up to 10 most recent live rows |
| `GET /news/{slug}` | `NewsController@show` | full record by locale-specific slug |
| `GET /news/{slug}/related` | `NewsController@related` | up to 4 other rows in same category |
| `GET /news/{slug}/comments` | `NewsCommentController@index` | approved comments (with replies) |
| `GET /news-categories` | `NewsCategoryController@index` | all active categories |

`{slug}` resolves via a `Route::bind` callback that finds the news whose `translations.slug` matches the path for any locale and that is live.

### Authenticated (`auth:api`)

| route | controller method | purpose |
|---|---|---|
| `POST /news/{slug}/like` | `NewsLikeController@toggle` | idempotent toggle, returns `{ liked: bool, like_count: int }` |
| `POST /news/{slug}/comments` | `NewsCommentController@store` | body: `{ body, parent_id? }`. Created with `is_approved=false`. Returns the pending comment. |
| `DELETE /news/comments/{id}` | `NewsCommentController@destroy` | only the comment's author can delete |

### Resources

`NewsResource`:
```json
{
  "id": 1,
  "slug": "<current locale slug>",
  "title": "<trans('title')>",
  "excerpt": "<trans('excerpt')>",
  "body": "<trans('body')>",
  "cover_url": "...",
  "thumbnail_url": "...",
  "category": { "id", "slug", "name" } | null,
  "author": { "id", "name", "avatar_url" } | null,
  "view_count": 0,
  "like_count": 0,
  "comment_count": 0,
  "is_liked": false,
  "is_featured": false,
  "published_at": "2026-05-14T...",
  "translations": { ... }  // only when ?with_translations=1
}
```

`is_liked` is computed: `auth()->check() && $this->likes()->where('user_id', auth()->id())->exists()`. The N+1 problem is solved by eager-loading user likes once at controller level.

`NewsCommentResource`:
```json
{
  "id": 1,
  "body": "...",
  "author": { "id", "name", "avatar_url" },
  "created_at": "...",
  "replies_count": 2,
  "replies": [ ...recursive ]   // only on detail; not on list
}
```

`NewsCategoryResource`: id, slug, name, description, news_count (counted via `withCount('news')`).

## Filament admin

### `NewsResource`

Form (Filament 4 Schema):
- Section "Asosiy" — `static::translationTabs()` (same helper pattern as BookResource), one tab per active language. Tab fields: `title` (required for default locale), `slug` (auto from title), `excerpt` (Textarea), `body` (`Filament\Forms\Components\RichEditor` → `disableAllToolbarButtons()->enableToolbarButtons([...])` to lock to bold/italic/link/list/heading), `meta_title`, `meta_description`.
- Section "Rasm" — `FileUpload::make('cover_image')` on `uploads` disk, directory `tenants/{id}/news`. Auto-creates `thumbnail` via Intervention (cropped to 600×400 webp). Both fields are language-independent (rasmlar barcha tillar uchun bitta).
- Section "Sozlamalar" (2-column Grid):
  - `Select::make('news_category_id')` — relationship('category', 'id') + `getOptionLabelFromRecordUsing(fn ($r) => $r->trans('name'))` (Phase D pattern)
  - `Select::make('status')` — NewsStatus options
  - `Toggle::make('is_featured')`
  - `DateTimePicker::make('published_at')` — helper "Bo'sh qoldirsangiz hozir nashr qilinadi"

Table columns: cover thumbnail (ImageColumn), title (`getStateUsing(fn (News $r) => $r->trans('title'))` + search via translations), category, status badge, `view_count`, `like_count`, `comment_count`, `published_at`.

Filters: status, category, is_featured, "Faqat tasdiqlanmagan sharhlari bor" (whereHas pending comments).

Relation managers:
- `CommentsRelationManager` — list + approve + delete (read-only fields, action buttons only)
- `LikesRelationManager` — list-only, "Kim like qilgan" ko'rinish

Pages: `ListNews`, `CreateNews` (uses `HandlesTranslations` trait), `EditNews` (same trait + delete action).

Navigation group: **"Yangiliklar"**, sort order before Settings.

### `NewsCategoryResource`

Similar shape to existing `CategoryResource`: translation tabs (name, description, slug), parent_id select, icon, color, sort_order, is_active toggle. Uses `HandlesTranslations` trait.

### `NewsCommentResource`

Dedicated moderation panel:
- Table columns: news title (linked), comment body (truncated 80 chars), author name, status badge (pending/approved), created_at
- Default filter: pending only (admin clears to see all)
- Row actions: **Tasdiqlash** (sets `is_approved=true`), **O'chirish** (deletes), **Foydalanuvchi profili** (link)
- Bulk actions: tasdiqlash, o'chirish
- No `create` page — comments come from the API only

## Frontend (Angular)

### Services

```
src/app/core/services/
  news.service.ts                    # list, featured, latest, show, related
  news-category.service.ts
  news-comment.service.ts            # list, create, delete
  news-like.service.ts               # toggle
```

Each service uses the existing `ApiService` (which now sends `X-Locale` automatically).

### Models

```
src/app/core/models/
  news.model.ts                      # News, NewsCategory, NewsComment, NewsLikeResponse
```

### Routes

```typescript
{ path: 'news', loadComponent: () => import('./features/news/pages/news-list/news-list.component').then(m => m.NewsListComponent) },
{ path: 'news/:slug', loadComponent: () => import('./features/news/pages/news-detail/news-detail.component').then(m => m.NewsDetailComponent) },
```

### Pages

`NewsListComponent`:
- Loads featured (first) + paginated list
- Hero section: first featured article (large cover, title, excerpt, date, "Batafsil o'qish" link)
- Grid below: 4 columns desktop / 2 tablet / 1 mobile of `<app-news-card>`
- Pagination: "Ko'proq yuklash" button (append, not replace)
- Reacts to `LocaleService.current()` via `effect()` → refetches list

`NewsDetailComponent`:
- Loads news by `:slug`
- Sections: hero cover, title, byline (author + date), `[innerHTML]="body | safeHtml"`, `<app-like-button>`, `<app-comment-list>`, related news at bottom
- Calls `news.incrementView(slug)` once on mount (fire-and-forget)

### Sub-components

- `<app-news-hero>` — large featured card
- `<app-news-card>` — listing card (image, date, title, excerpt 2 lines)
- `<app-like-button>` — heart icon + count; on click → `NewsLikeService.toggle()`; disables when not logged in (shows tooltip "Yoqtirish uchun kiring")
- `<app-comment-list>` — accepts comments input, renders nested replies (1 level), has `<app-comment-form>` at top (auth gate)
- `<app-comment-form>` — textarea + "Yuborish" button; on submit calls `NewsCommentService.create()`, shows "Sharhingiz moderatsiyada" toast

### Pipes

- `safeHtml` pipe (DomSanitizer.bypassSecurityTrustHtml) — renders sanitized `body`. Body is already purified server-side; pipe just satisfies Angular's security model.

### UI strings

Add to `lang/uz.json`, `ru.json`, `en.json`:
```
news.title — "Yangiliklar" / "Новости" / "News"
news.view_all — "Barcha yangiliklar" / "Все новости" / "View all"
news.read_more — "Batafsil o'qish" / "Читать далее" / "Read more"
news.published_at — "Nashr sanasi" / "Дата публикации" / "Published"
news.author — "Muallif" / "Автор" / "Author"
news.like — "Yoqdi" / "Нравится" / "Like"
news.comment — "Sharh" / "Комментарий" / "Comment"
news.comment.placeholder — "Sharhingizni yozing..." / "Напишите комментарий..." / "Write a comment..."
news.comment.submit — "Yuborish" / "Отправить" / "Submit"
news.comment.moderation — "Sharhingiz moderatsiyaga yuborildi" / "Комментарий отправлен на модерацию" / "Your comment is pending review"
news.comment.login_required — "Sharh yozish uchun tizimga kiring" / "Войдите чтобы комментировать" / "Sign in to comment"
news.no_results — "Hozircha yangiliklar yo'q" / "Пока новостей нет" / "No news yet"
news.related — "O'xshash yangiliklar" / "Похожие новости" / "Related news"
news.reply — "Javob berish" / "Ответить" / "Reply"
news.load_more — "Ko'proq yuklash" / "Загрузить ещё" / "Load more"
```

### Layout navigation

Currently the "Yangiliklar" nav link is a placeholder `<a>`. Update to `<a routerLink="/news" routerLinkActive="active">{{ 'news.title' | t }}</a>`.

## Migration strategy

No backfill — news is a new feature with no existing data. Migrations create empty tables. The `tenants` table already exists; foreign keys to it work.

Migration order:
1. `create_news_categories_table`
2. `create_news_table`
3. `create_news_category_translations_table`
4. `create_news_translations_table`
5. `create_news_comments_table`
6. `create_news_likes_table`

Rollback: standard `dropIfExists` in `down()` for each.

## Testing

Feature tests under `tests/Feature/News/`:

- `NewsCrudTest` — admin creates news with translations in 2 locales, edits, deletes; verifies translation rows persisted and parent counters preserved.
- `NewsListingTest` — `GET /news` returns only live rows in current locale; `?category=` filters; `?search=` matches translation table.
- `NewsCommentModerationTest` — user POSTs comment → `is_approved=false`, not visible on public list; admin approves → visible, parent's `comment_count` increments.
- `NewsLikeToggleTest` — first POST creates a like and increments counter; second POST removes it.
- `NewsScheduledPublishingTest` — news with future `published_at` is not in `live()` scope; after time passes (manipulated via Carbon::setTestNow), it appears.
- `NewsTranslationFallbackTest` — request `?lang=ru` for a news that only has `uz` translation → falls back to tenant default locale.

Unit tests:
- `NewsObserverTest` — first publish sets `published_at` if null.
- `NewsCommentObserverTest` — rejects reply to a reply (1-level constraint).

## Build sequence

Stage 1 — Foundation (~1 day):
- 6 migrations (categories, news, both translation tables, comments, likes)
- NewsStatus enum
- 6 models + HasTranslations wiring
- 3 observers + attribute registration

Stage 2 — Admin (~2 days):
- NewsCategoryResource (CRUD)
- NewsResource (rich editor + translation tabs + cover upload + scheduled publishing UI)
- NewsCommentResource (moderation panel)
- Filament navigation group

Stage 3 — API (~1 day):
- 4 controllers (News, NewsCategory, NewsComment, NewsLike)
- 3 resources (News, NewsCategory, NewsComment)
- Route bindings + registration
- Like toggle with `firstOrCreate`/`delete` semantics

Stage 4 — Frontend (~2 days):
- 4 services
- News models + safe-html pipe
- NewsListComponent + NewsDetailComponent
- 5 sub-components (hero, card, like-button, comment-list, comment-form)
- Routes registration + layout link
- New UI strings in lang files

Stage 5 — Tests (~1 day):
- 6 feature tests + 2 unit tests
- Manual smoke test on dev tenant: create news in uz, add ru translation, publish scheduled, view on frontend, like, comment, approve

**Total: 6-7 working days.**

## Open questions / deferred decisions

- **Email notifications** — when a user's comment is approved, should they get an email? Deferred until users actually request it.
- **Comment edit window** — allow user to edit their own comment within 5 minutes? Out for now.
- **Soft delete for comments vs hard delete** — currently hard delete; switch to soft if moderation history becomes a requirement.
- **News feed for subscribers** — RSS/Atom feed at `/news/feed.xml`. Easy to add later; defer.
- **Push notifications** — out of scope until we add notification infrastructure.
- **Multi-author collaboration** — currently single author per article; revisit if editorial workflow becomes a requirement.

## Invariants and constraints

- Every news has exactly one row per (locale, slug) — enforced by `UNIQUE(tenant_id, locale, slug)` on `news_translations`.
- A news is "live" iff `status=published AND published_at <= now()`. Public API endpoints filter on this.
- A user can like a news at most once — enforced by `UNIQUE(news_id, user_id)`.
- A comment can have at most 1 level of parent — enforced by `NewsCommentObserver::creating()`.
- A pending comment is never visible to the public — enforced by the `approved()` scope on the public listing endpoint.
- Denormalized counters on `news` (`like_count`, `comment_count`) are kept in sync by `NewsObserver`, `NewsCommentObserver`, `NewsLikeObserver`. Tests must verify they match the related-row counts after CRUD.

See also:
- `docs/superpowers/specs/2026-05-14-multi-language-design.md` — i18n contract this module reuses.
