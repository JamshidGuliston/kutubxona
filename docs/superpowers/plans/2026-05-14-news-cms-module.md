# News / CMS Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a full-featured news module: per-tenant news with i18n translations, rich-text body, scheduled publishing, user likes, and moderated comments. Filament admin, REST API, Angular frontend.

**Architecture:** Standalone `App\Domain\News` aggregate that reuses the existing `HasTranslations` trait and `TenantScope`. Six new tables, six models, three observers for counter sync, three Filament resources, four API controllers, and a new Angular feature module that consumes the locale-aware API.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL, Filament 4, Pest 2 (optional tests), Angular 21 (standalone components + signals).

**Reference spec:** `docs/superpowers/specs/2026-05-14-news-cms-design.md`

---

## File Structure

### Backend — new files

```
database/migrations/
  2026_05_14_000010_create_news_categories_table.php
  2026_05_14_000011_create_news_table.php
  2026_05_14_000012_create_news_category_translations_table.php
  2026_05_14_000013_create_news_translations_table.php
  2026_05_14_000014_create_news_comments_table.php
  2026_05_14_000015_create_news_likes_table.php

app/Domain/News/
  Enums/NewsStatus.php
  Models/News.php
  Models/NewsTranslation.php
  Models/NewsCategory.php
  Models/NewsCategoryTranslation.php
  Models/NewsComment.php
  Models/NewsLike.php
  Observers/NewsObserver.php
  Observers/NewsCommentObserver.php
  Observers/NewsLikeObserver.php

app/Filament/Admin/Resources/
  NewsResource.php
  NewsResource/Pages/{ListNews,CreateNews,EditNews}.php
  NewsResource/RelationManagers/CommentsRelationManager.php
  NewsCategoryResource.php
  NewsCategoryResource/Pages/{ListNewsCategories,CreateNewsCategory,EditNewsCategory}.php
  NewsCommentResource.php
  NewsCommentResource/Pages/ListNewsComments.php

app/Interfaces/Http/Controllers/V1/News/
  NewsController.php
  NewsCategoryController.php
  NewsCommentController.php
  NewsLikeController.php

app/Interfaces/Http/Resources/News/
  NewsResource.php
  NewsCategoryResource.php
  NewsCommentResource.php
```

### Backend — modified files

```
routes/api.php           — register news routes
lang/uz.json             — add news.* keys
lang/ru.json             — add news.* keys
lang/en.json             — add news.* keys
```

### Frontend — new files

```
src/app/core/models/news.model.ts

src/app/core/services/
  news.service.ts
  news-category.service.ts
  news-comment.service.ts
  news-like.service.ts

src/app/shared/pipes/safe-html.pipe.ts

src/app/features/news/
  pages/news-list/news-list.component.ts
  pages/news-detail/news-detail.component.ts
  components/news-card/news-card.component.ts
  components/news-hero/news-hero.component.ts
  components/like-button/like-button.component.ts
  components/comment-list/comment-list.component.ts
  components/comment-form/comment-form.component.ts
```

### Frontend — modified files

```
src/app/app.routes.ts                          — register /news routes
src/app/features/layout/layout.component.ts    — link nav "Yangiliklar" to /news
src/app/core/services/index.ts                 — export new services
```

---

## Stage 1 — Foundation: Migrations + Enum + Models

### Task 1.1: NewsStatus enum

**Files:**
- Create: `app/Domain/News/Enums/NewsStatus.php`

- [ ] **Step 1: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Enums;

enum NewsStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Qoralama',
            self::Published => 'Nashr qilingan',
            self::Archived  => 'Arxivlangan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft     => 'gray',
            self::Published => 'success',
            self::Archived  => 'warning',
        };
    }
}
```

- [ ] **Step 2: Commit**

```
git add app/Domain/News/Enums/NewsStatus.php
git commit -m "feat(news): add NewsStatus enum (draft/published/archived)"
```

---

### Task 1.2: Migration — `news_categories`

**Files:**
- Create: `database/migrations/2026_05_14_000010_create_news_categories_table.php`

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
        Schema::create('news_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('news_categories')->nullOnDelete();
            $table->string('icon')->nullable();
            $table->char('color', 7)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_categories');
    }
};
```

- [ ] **Step 2: Run migration**

```
php artisan migrate
```
Expected output: `2026_05_14_000010_create_news_categories_table ... DONE`.

- [ ] **Step 3: Commit**

```
git add database/migrations/2026_05_14_000010_create_news_categories_table.php
git commit -m "feat(news): create news_categories table"
```

---

### Task 1.3: Migration — `news`

**Files:**
- Create: `database/migrations/2026_05_14_000011_create_news_table.php`

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
        Schema::create('news', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('news_category_id')->nullable()->constrained('news_categories')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cover_image', 500)->nullable();
            $table->string('thumbnail', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'published_at']);
            $table->index(['tenant_id', 'is_featured']);
            $table->index('news_category_id');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
```

- [ ] **Step 2: Run migration**

```
php artisan migrate
```

- [ ] **Step 3: Commit**

```
git add database/migrations/2026_05_14_000011_create_news_table.php
git commit -m "feat(news): create news table"
```

---

### Task 1.4: Migration — `news_category_translations`

**Files:**
- Create: `database/migrations/2026_05_14_000012_create_news_category_translations_table.php`

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
        Schema::create('news_category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('news_category_id')->constrained('news_categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->timestamps();

            $table->unique(['news_category_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_category_translations');
    }
};
```

- [ ] **Step 2: Run migration**

```
php artisan migrate
```

- [ ] **Step 3: Commit**

```
git add database/migrations/2026_05_14_000012_create_news_category_translations_table.php
git commit -m "feat(news): create news_category_translations table"
```

---

### Task 1.5: Migration — `news_translations`

**Files:**
- Create: `database/migrations/2026_05_14_000013_create_news_translations_table.php`

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
        Schema::create('news_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 500);
            $table->string('slug', 500);
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();

            $table->unique(['news_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_translations');
    }
};
```

- [ ] **Step 2: Run migration**

```
php artisan migrate
```

- [ ] **Step 3: Commit**

```
git add database/migrations/2026_05_14_000013_create_news_translations_table.php
git commit -m "feat(news): create news_translations table"
```

---

### Task 1.6: Migration — `news_comments`

**Files:**
- Create: `database/migrations/2026_05_14_000014_create_news_comments_table.php`

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
        Schema::create('news_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('news_comments')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            $table->index(['news_id', 'is_approved', 'created_at']);
            $table->index('user_id');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_comments');
    }
};
```

- [ ] **Step 2: Run migration**

```
php artisan migrate
```

- [ ] **Step 3: Commit**

```
git add database/migrations/2026_05_14_000014_create_news_comments_table.php
git commit -m "feat(news): create news_comments table"
```

---

### Task 1.7: Migration — `news_likes`

**Files:**
- Create: `database/migrations/2026_05_14_000015_create_news_likes_table.php`

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
        Schema::create('news_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['news_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_likes');
    }
};
```

- [ ] **Step 2: Run migration**

```
php artisan migrate
```

- [ ] **Step 3: Commit**

```
git add database/migrations/2026_05_14_000015_create_news_likes_table.php
git commit -m "feat(news): create news_likes table"
```

---

### Task 1.8: NewsCategory + NewsCategoryTranslation models

**Files:**
- Create: `app/Domain/News/Models/NewsCategory.php`
- Create: `app/Domain/News/Models/NewsCategoryTranslation.php`

- [ ] **Step 1: Write NewsCategoryTranslation**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class NewsCategoryTranslation extends Model
{
    protected $table = 'news_category_translations';

    protected $fillable = ['tenant_id', 'news_category_id', 'locale', 'name', 'description', 'slug'];

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
        return $this->belongsTo(NewsCategory::class, 'news_category_id');
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

- [ ] **Step 2: Write NewsCategory**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Localization\Contracts\HasTranslations as HasTranslationsContract;
use App\Domain\Localization\Traits\HasTranslations;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string|null $icon
 * @property string|null $color
 * @property int $sort_order
 * @property bool $is_active
 */
final class NewsCategory extends Model implements HasTranslationsContract
{
    use HasTranslations;

    public const TRANSLATION_MODEL = NewsCategoryTranslation::class;
    public const TRANSLATABLE_FIELDS = ['name', 'description', 'slug'];

    protected $table = 'news_categories';

    protected $fillable = [
        'tenant_id', 'parent_id', 'icon', 'color', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $r): void {
            if (app()->has('tenant') && empty($r->tenant_id)) {
                $r->tenant_id = app('tenant')->id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function news(): HasMany
    {
        return $this->hasMany(News::class, 'news_category_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeRoots(Builder $q): Builder
    {
        return $q->whereNull('parent_id');
    }
}
```

- [ ] **Step 3: Smoke test**

```
php artisan tinker --execute="echo class_exists('App\Domain\News\Models\NewsCategory') ? 'OK' : 'MISSING';"
```
Expected: `OK`.

PHP path on this OSPanel install: `/d/OSPanel/modules/PHP-8.3/PHP/php.exe` if `php` is not in PATH.

- [ ] **Step 4: Commit**

```
git add app/Domain/News/Models/NewsCategory.php app/Domain/News/Models/NewsCategoryTranslation.php
git commit -m "feat(news): add NewsCategory + NewsCategoryTranslation models"
```

---

### Task 1.9: News + NewsTranslation models

**Files:**
- Create: `app/Domain/News/Models/News.php`
- Create: `app/Domain/News/Models/NewsTranslation.php`

- [ ] **Step 1: Write NewsTranslation**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class NewsTranslation extends Model
{
    protected $table = 'news_translations';

    protected $fillable = [
        'tenant_id', 'news_id', 'locale',
        'title', 'slug', 'excerpt', 'body',
        'meta_title', 'meta_description',
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

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
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

- [ ] **Step 2: Write News**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Localization\Contracts\HasTranslations as HasTranslationsContract;
use App\Domain\Localization\Traits\HasTranslations;
use App\Domain\News\Enums\NewsStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $news_category_id
 * @property int|null $author_id
 * @property string|null $cover_image
 * @property string|null $thumbnail
 * @property NewsStatus $status
 * @property bool $is_featured
 * @property int $view_count
 * @property int $like_count
 * @property int $comment_count
 * @property \Carbon\Carbon|null $published_at
 */
final class News extends Model implements HasTranslationsContract
{
    use HasFactory;
    use HasTranslations;
    use SoftDeletes;

    public const TRANSLATION_MODEL = NewsTranslation::class;
    public const TRANSLATABLE_FIELDS = ['title', 'slug', 'excerpt', 'body', 'meta_title', 'meta_description'];

    protected $table = 'news';

    protected $fillable = [
        'tenant_id', 'news_category_id', 'author_id',
        'cover_image', 'thumbnail',
        'status', 'is_featured',
        'view_count', 'like_count', 'comment_count',
        'published_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'status'        => NewsStatus::class,
        'is_featured'   => 'boolean',
        'view_count'    => 'integer',
        'like_count'    => 'integer',
        'comment_count' => 'integer',
        'published_at'  => 'datetime',
    ];

    protected $appends = ['cover_url', 'thumbnail_url'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $r): void {
            if (app()->has('tenant') && empty($r->tenant_id)) {
                $r->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($r->created_by)) {
                $r->created_by = auth()->id();
            }
        });

        static::updating(function (self $r): void {
            if (auth()->check()) {
                $r->updated_by = auth()->id();
            }
        });
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function category(): BelongsTo { return $this->belongsTo(NewsCategory::class, 'news_category_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function comments(): HasMany { return $this->hasMany(NewsComment::class, 'news_id'); }
    public function approvedComments(): HasMany
    {
        return $this->hasMany(NewsComment::class, 'news_id')->where('is_approved', true);
    }
    public function likes(): HasMany { return $this->hasMany(NewsLike::class, 'news_id'); }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', NewsStatus::Published->value)
                 ->whereNotNull('published_at')
                 ->where('published_at', '<=', now());
    }

    public function scopeScheduled(Builder $q): Builder
    {
        return $q->where('status', NewsStatus::Published->value)
                 ->whereNotNull('published_at')
                 ->where('published_at', '>', now());
    }

    public function scopeFeatured(Builder $q): Builder { return $q->where('is_featured', true); }
    public function scopeByCategory(Builder $q, int $categoryId): Builder { return $q->where('news_category_id', $categoryId); }
    public function scopePopular(Builder $q): Builder { return $q->orderByDesc('view_count'); }
    public function scopeRecent(Builder $q): Builder { return $q->orderByDesc('published_at'); }

    public function isLive(): bool
    {
        return $this->status === NewsStatus::Published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function recalculateCounts(): void
    {
        $this->update([
            'like_count'    => $this->likes()->count(),
            'comment_count' => $this->approvedComments()->count(),
        ]);
    }

    protected function coverUrl(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            if (!$this->cover_image) return null;
            return str_starts_with($this->cover_image, 'http')
                ? $this->cover_image
                : Storage::disk('uploads')->url($this->cover_image);
        });
    }

    protected function thumbnailUrl(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            $path = $this->thumbnail ?: $this->cover_image;
            if (!$path) return null;
            return str_starts_with($path, 'http') ? $path : Storage::disk('uploads')->url($path);
        });
    }
}
```

- [ ] **Step 3: Smoke test**

```
php artisan tinker --execute="echo class_exists('App\Domain\News\Models\News') ? 'OK' : 'MISSING';"
```

- [ ] **Step 4: Commit**

```
git add app/Domain/News/Models/News.php app/Domain/News/Models/NewsTranslation.php
git commit -m "feat(news): add News + NewsTranslation models with HasTranslations"
```

---

### Task 1.10: NewsComment model

**Files:**
- Create: `app/Domain/News/Models/NewsComment.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $news_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $body
 * @property bool $is_approved
 */
final class NewsComment extends Model
{
    protected $table = 'news_comments';

    protected $fillable = ['tenant_id', 'news_id', 'user_id', 'parent_id', 'body', 'is_approved'];

    protected $casts = ['is_approved' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $c): void {
            if (app()->has('tenant') && empty($c->tenant_id)) {
                $c->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($c->user_id)) {
                $c->user_id = auth()->id();
            }
        });
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function news(): BelongsTo { return $this->belongsTo(News::class, 'news_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function replies(): HasMany { return $this->hasMany(self::class, 'parent_id'); }

    public function scopeApproved(Builder $q): Builder { return $q->where('is_approved', true); }
    public function scopePending(Builder $q): Builder { return $q->where('is_approved', false); }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }
}
```

- [ ] **Step 2: Commit**

```
git add app/Domain/News/Models/NewsComment.php
git commit -m "feat(news): add NewsComment model"
```

---

### Task 1.11: NewsLike model

**Files:**
- Create: `app/Domain/News/Models/NewsLike.php`

- [ ] **Step 1: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NewsLike extends Model
{
    protected $table = 'news_likes';

    // Only created_at — no updated_at on likes.
    public $timestamps = false;
    protected $dates = ['created_at'];

    protected $fillable = ['tenant_id', 'news_id', 'user_id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $l): void {
            if (app()->has('tenant') && empty($l->tenant_id)) {
                $l->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($l->user_id)) {
                $l->user_id = auth()->id();
            }
            if (empty($l->created_at)) {
                $l->created_at = now();
            }
        });
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function news(): BelongsTo { return $this->belongsTo(News::class, 'news_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
```

- [ ] **Step 2: Commit**

```
git add app/Domain/News/Models/NewsLike.php
git commit -m "feat(news): add NewsLike model"
```

---

### Task 1.12: NewsObserver — auto-set published_at on first publish

**Files:**
- Create: `app/Domain/News/Observers/NewsObserver.php`
- Modify: `app/Domain/News/Models/News.php` — register observer

- [ ] **Step 1: Write the observer**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Observers;

use App\Domain\News\Enums\NewsStatus;
use App\Domain\News\Models\News;

final class NewsObserver
{
    public function saving(News $news): void
    {
        if ($news->status === NewsStatus::Published
            && $news->published_at === null
        ) {
            $news->published_at = now();
        }
    }
}
```

- [ ] **Step 2: Register on the model**

Add to `app/Domain/News/Models/News.php` — add import after existing `use` statements:

```php
use App\Domain\News\Observers\NewsObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
```

Add attribute directly above `final class News`:

```php
#[ObservedBy([NewsObserver::class])]
final class News extends Model implements HasTranslationsContract
```

- [ ] **Step 3: Commit**

```
git add app/Domain/News/Observers/NewsObserver.php app/Domain/News/Models/News.php
git commit -m "feat(news): observer sets published_at automatically on first publish"
```

---

### Task 1.13: NewsCommentObserver — enforce 1-level nesting + counter sync

**Files:**
- Create: `app/Domain/News/Observers/NewsCommentObserver.php`
- Modify: `app/Domain/News/Models/NewsComment.php`

- [ ] **Step 1: Write the observer**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Observers;

use App\Domain\News\Models\NewsComment;

final class NewsCommentObserver
{
    public function creating(NewsComment $comment): void
    {
        if ($comment->parent_id === null) {
            return;
        }
        $parent = NewsComment::find($comment->parent_id);
        if ($parent && $parent->parent_id !== null) {
            throw new \InvalidArgumentException(
                'News comments support only one level of nesting; cannot reply to a reply.'
            );
        }
    }

    public function saved(NewsComment $comment): void
    {
        // Refresh the parent news' comment_count if approval state changed
        if ($comment->wasChanged('is_approved')) {
            $comment->news?->recalculateCounts();
        }
    }

    public function deleted(NewsComment $comment): void
    {
        $comment->news?->recalculateCounts();
    }
}
```

- [ ] **Step 2: Register on the model**

Modify `app/Domain/News/Models/NewsComment.php` — add imports:

```php
use App\Domain\News\Observers\NewsCommentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
```

Add attribute:

```php
#[ObservedBy([NewsCommentObserver::class])]
final class NewsComment extends Model
```

- [ ] **Step 3: Commit**

```
git add app/Domain/News/Observers/NewsCommentObserver.php app/Domain/News/Models/NewsComment.php
git commit -m "feat(news): comment observer enforces 1-level nesting and syncs counter"
```

---

### Task 1.14: NewsLikeObserver — counter sync

**Files:**
- Create: `app/Domain/News/Observers/NewsLikeObserver.php`
- Modify: `app/Domain/News/Models/NewsLike.php`

- [ ] **Step 1: Write the observer**

```php
<?php

declare(strict_types=1);

namespace App\Domain\News\Observers;

use App\Domain\News\Models\NewsLike;

final class NewsLikeObserver
{
    public function created(NewsLike $like): void
    {
        $like->news?->recalculateCounts();
    }

    public function deleted(NewsLike $like): void
    {
        $like->news?->recalculateCounts();
    }
}
```

- [ ] **Step 2: Register on the model**

Modify `app/Domain/News/Models/NewsLike.php` — add imports and attribute:

```php
use App\Domain\News\Observers\NewsLikeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
```

```php
#[ObservedBy([NewsLikeObserver::class])]
final class NewsLike extends Model
```

- [ ] **Step 3: Smoke test the whole stack**

```
php artisan tinker --execute="
\$cat = \App\Domain\News\Models\NewsCategory::create(['tenant_id' => 2]);
\App\Domain\News\Models\NewsCategoryTranslation::create([
    'tenant_id' => 2, 'news_category_id' => \$cat->id, 'locale' => 'uz', 'name' => 'Test',
]);
\$n = \App\Domain\News\Models\News::create(['tenant_id' => 2, 'news_category_id' => \$cat->id, 'status' => 'draft']);
\App\Domain\News\Models\NewsTranslation::create([
    'tenant_id' => 2, 'news_id' => \$n->id, 'locale' => 'uz',
    'title' => 'Test yangilik', 'body' => '<p>matn</p>',
]);
echo 'created news id=' . \$n->id . ' title=' . \$n->trans('title') . PHP_EOL;
\$n->delete(); \$cat->delete();
echo 'cleanup OK';
"
```
Expected: `created news id=N title=Test yangilik` then `cleanup OK`.

- [ ] **Step 4: Commit**

```
git add app/Domain/News/Observers/NewsLikeObserver.php app/Domain/News/Models/NewsLike.php
git commit -m "feat(news): like observer syncs denormalized like_count"
```

---

## Stage 2 — Filament Admin

### Task 2.1: NewsCategoryResource + pages

**Files:**
- Create: `app/Filament/Admin/Resources/NewsCategoryResource.php`
- Create: `app/Filament/Admin/Resources/NewsCategoryResource/Pages/ListNewsCategories.php`
- Create: `app/Filament/Admin/Resources/NewsCategoryResource/Pages/CreateNewsCategory.php`
- Create: `app/Filament/Admin/Resources/NewsCategoryResource/Pages/EditNewsCategory.php`

- [ ] **Step 1: Resource class**

```php
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
    protected static ?string $navigationGroup = 'Yangiliklar';
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
                ->getOptionLabelFromRecordUsing(fn (NewsCategory $r) => $r->trans('name'))
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
```

- [ ] **Step 2: List page**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsCategoryResource\Pages;

use App\Filament\Admin\Resources\NewsCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsCategories extends ListRecords
{
    protected static string $resource = NewsCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

- [ ] **Step 3: Create page**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsCategoryResource\Pages;

use App\Filament\Admin\Resources\NewsCategoryResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsCategory extends CreateRecord
{
    use HandlesTranslations;

    protected static string $resource = NewsCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractTranslations($data);
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
```

- [ ] **Step 4: Edit page**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsCategoryResource\Pages;

use App\Filament\Admin\Resources\NewsCategoryResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsCategory extends EditRecord
{
    use HandlesTranslations;

    protected static string $resource = NewsCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
```

- [ ] **Step 5: Cache + smoke test**

```
php artisan filament:cache-components
```

Open `/admin/news-categories` in a browser. Create a category. Verify it appears.

- [ ] **Step 6: Commit**

```
git add app/Filament/Admin/Resources/NewsCategoryResource.php app/Filament/Admin/Resources/NewsCategoryResource/
git commit -m "feat(news): add NewsCategoryResource Filament CRUD"
```

---

### Task 2.2: NewsResource form + table

**Files:**
- Create: `app/Filament/Admin/Resources/NewsResource.php`

- [ ] **Step 1: Write the resource**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Localization\Models\TenantLanguage;
use App\Domain\News\Enums\NewsStatus;
use App\Domain\News\Models\News;
use App\Domain\News\Models\NewsCategory;
use App\Filament\Admin\Resources\NewsResource\Pages;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-newspaper';
    protected static ?string $navigationGroup = 'Yangiliklar';
    protected static ?string $navigationLabel = 'Yangiliklar';
    protected static ?string $modelLabel = 'Yangilik';
    protected static ?string $pluralModelLabel = 'Yangiliklar';
    protected static ?int $navigationSort = 10;

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
            Section::make('Asosiy')->schema([
                static::translationTabs()->columnSpanFull(),
            ]),

            Section::make('Rasm')->schema([
                FileUpload::make('cover_image')
                    ->label('Asosiy rasm')
                    ->image()
                    ->imagePreviewHeight('200')
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/news')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120)
                    ->columnSpanFull(),
                FileUpload::make('thumbnail')
                    ->label('Kichik rasm (ixtiyoriy)')
                    ->image()
                    ->disk('uploads')
                    ->directory(fn () => 'tenants/' . (auth()->user()?->tenant_id ?? 0) . '/news/thumbs')
                    ->visibility('public')
                    ->maxSize(2048)
                    ->helperText("Bo'sh qoldirsangiz asosiy rasm ishlatiladi")
                    ->columnSpanFull(),
            ]),

            Section::make('Sozlamalar')->schema([
                Grid::make(2)->schema([
                    Select::make('news_category_id')
                        ->label('Kategoriya')
                        ->relationship('category', 'id')
                        ->getOptionLabelFromRecordUsing(fn (NewsCategory $r) => $r->trans('name'))
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Select::make('status')
                        ->label('Holat')
                        ->options(collect(NewsStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                        ->default(NewsStatus::Draft->value)
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Toggle::make('is_featured')->label('Hero pozitsiyasi'),
                    DateTimePicker::make('published_at')
                        ->label('Nashr sanasi')
                        ->helperText("Bo'sh qoldirilsa va holat 'Nashr qilingan' bo'lsa, hozir bilan to'ldiriladi. Kelajak sana = rejalashtirilgan."),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->disk('uploads')
                    ->height(48)->width(72)
                    ->defaultImageUrl('https://placehold.co/72x48/e2e8f0/94a3b8?text=News'),
                TextColumn::make('title')
                    ->label('Sarlavha')
                    ->getStateUsing(fn (News $r) => $r->trans('title'))
                    ->searchable(query: fn ($q, $s) => $q->whereHas('translations', fn ($t) => $t->where('title', 'ilike', "%{$s}%")))
                    ->limit(50),
                TextColumn::make('category.id')
                    ->label('Kategoriya')
                    ->formatStateUsing(fn ($state, News $r) => $r->category?->trans('name') ?? '—'),
                TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof NewsStatus ? $state->label() : NewsStatus::from((string) $state)->label())
                    ->color(fn ($state) => $state instanceof NewsStatus ? $state->color() : NewsStatus::from((string) $state)->color()),
                IconColumn::make('is_featured')->label('Hero')->boolean(),
                TextColumn::make('view_count')->label('Ko\'rishlar')->sortable(),
                TextColumn::make('like_count')->label('Like')->sortable(),
                TextColumn::make('comment_count')->label('Sharhlar')->sortable(),
                TextColumn::make('published_at')->label('Nashr')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(NewsStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                TernaryFilter::make('is_featured')->label('Hero pozitsiyasi'),
            ])
            ->defaultSort('published_at', 'desc')
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListNews::route('/'),
            'create' => Pages\CreateNews::route('/create'),
            'edit'   => Pages\EditNews::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\Resources\NewsResource\RelationManagers\CommentsRelationManager::class,
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
            TextInput::make("translations.{$locale}.title")
                ->label('Sarlavha')->required($isDefault)->maxLength(500)->columnSpanFull(),
            TextInput::make("translations.{$locale}.slug")
                ->label('URL slug')->maxLength(500)->columnSpanFull()
                ->helperText("Bo'sh qoldirsangiz avtomatik yaratiladi"),
            Textarea::make("translations.{$locale}.excerpt")
                ->label('Qisqacha matn')->rows(3)->columnSpanFull(),
            RichEditor::make("translations.{$locale}.body")
                ->label('Asosiy matn')
                ->toolbarButtons([
                    'bold', 'italic', 'underline', 'strike',
                    'link', 'orderedList', 'bulletList',
                    'h2', 'h3', 'blockquote', 'codeBlock',
                ])
                ->required($isDefault)
                ->columnSpanFull(),
            TextInput::make("translations.{$locale}.meta_title")
                ->label('SEO sarlavha')->maxLength(255)->columnSpanFull(),
            Textarea::make("translations.{$locale}.meta_description")
                ->label('SEO tavsif')->rows(2)->columnSpanFull(),
        ];
    }
}
```

- [ ] **Step 2: Commit**

```
git add app/Filament/Admin/Resources/NewsResource.php
git commit -m "feat(news): add NewsResource form + table"
```

---

### Task 2.3: NewsResource Create/Edit pages

**Files:**
- Create: `app/Filament/Admin/Resources/NewsResource/Pages/ListNews.php`
- Create: `app/Filament/Admin/Resources/NewsResource/Pages/CreateNews.php`
- Create: `app/Filament/Admin/Resources/NewsResource/Pages/EditNews.php`

- [ ] **Step 1: ListNews**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsResource\Pages;

use App\Filament\Admin\Resources\NewsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNews extends ListRecords
{
    protected static string $resource = NewsResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

- [ ] **Step 2: CreateNews**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsResource\Pages;

use App\Filament\Admin\Resources\NewsResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Resources\Pages\CreateRecord;

class CreateNews extends CreateRecord
{
    use HandlesTranslations;

    protected static string $resource = NewsResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractTranslations($data);
        $data['tenant_id'] = auth()->user()->tenant_id;
        $data['author_id'] = $data['author_id'] ?? auth()->id();
        return $data;
    }
}
```

- [ ] **Step 3: EditNews**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsResource\Pages;

use App\Filament\Admin\Resources\NewsResource;
use App\Filament\Concerns\HandlesTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNews extends EditRecord
{
    use HandlesTranslations;

    protected static string $resource = NewsResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
```

- [ ] **Step 4: Cache + smoke test**

```
php artisan filament:cache-components
```

Open `/admin/news/create` in browser. Fill in form. Verify translation tabs render and saving works.

- [ ] **Step 5: Commit**

```
git add app/Filament/Admin/Resources/NewsResource/Pages/
git commit -m "feat(news): wire HandlesTranslations into NewsResource pages"
```

---

### Task 2.4: NewsResource CommentsRelationManager

**Files:**
- Create: `app/Filament/Admin/Resources/NewsResource/RelationManagers/CommentsRelationManager.php`

- [ ] **Step 1: Write the relation manager**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsResource\RelationManagers;

use App\Domain\News\Models\NewsComment;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Sharhlar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')->label('Matn')->disabled()->rows(4),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Foydalanuvchi'),
                TextColumn::make('body')->label('Sharh')->limit(80)->wrap(),
                IconColumn::make('is_approved')->label('Tasdiqlangan')->boolean(),
                TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_approved')->label('Tasdiq holati'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('approve')
                    ->label('Tasdiqlash')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (NewsComment $r) => ! $r->is_approved)
                    ->action(fn (NewsComment $r) => $r->update(['is_approved' => true])),
                DeleteAction::make(),
            ]);
    }
}
```

- [ ] **Step 2: Cache**

```
php artisan filament:cache-components
```

- [ ] **Step 3: Commit**

```
git add app/Filament/Admin/Resources/NewsResource/RelationManagers/CommentsRelationManager.php
git commit -m "feat(news): add CommentsRelationManager to NewsResource (moderation inline)"
```

---

### Task 2.5: NewsCommentResource (global moderation panel)

**Files:**
- Create: `app/Filament/Admin/Resources/NewsCommentResource.php`
- Create: `app/Filament/Admin/Resources/NewsCommentResource/Pages/ListNewsComments.php`

- [ ] **Step 1: Resource**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\News\Models\NewsComment;
use App\Filament\Admin\Resources\NewsCommentResource\Pages;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class NewsCommentResource extends Resource
{
    protected static ?string $model = NewsComment::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Yangiliklar';
    protected static ?string $navigationLabel = 'Sharhlar (moderatsiya)';
    protected static ?string $modelLabel = 'Sharh';
    protected static ?string $pluralModelLabel = 'Sharhlar';
    protected static ?int $navigationSort = 30;

    public static function getNavigationBadge(): ?string
    {
        $count = NewsComment::where('is_approved', false)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);  // No create/edit form — moderation only.
    }

    public static function canCreate(): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('news.id')
                    ->label('Yangilik')
                    ->formatStateUsing(fn ($state, NewsComment $r) => $r->news?->trans('title') ?? '—')
                    ->url(fn (NewsComment $r) => $r->news
                        ? \App\Filament\Admin\Resources\NewsResource::getUrl('edit', ['record' => $r->news_id])
                        : null)
                    ->limit(40),
                TextColumn::make('user.name')->label('Foydalanuvchi'),
                TextColumn::make('body')->label('Sharh')->limit(100)->wrap(),
                IconColumn::make('is_approved')->label('Tasdiq')->boolean(),
                TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_approved')
                    ->label('Holat')
                    ->trueLabel('Tasdiqlangan')
                    ->falseLabel('Kutilmoqda')
                    ->placeholder('Hammasi')
                    ->default(false),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('approve')
                    ->label('Tasdiqlash')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (NewsComment $r) => ! $r->is_approved)
                    ->action(fn (NewsComment $r) => $r->update(['is_approved' => true])),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkApprove')
                        ->label('Tanlanganlarini tasdiqlash')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(function (Collection $records): void {
                            $records->each(fn (NewsComment $r) => $r->update(['is_approved' => true]));
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNewsComments::route('/'),
        ];
    }
}
```

- [ ] **Step 2: List page**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\NewsCommentResource\Pages;

use App\Filament\Admin\Resources\NewsCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListNewsComments extends ListRecords
{
    protected static string $resource = NewsCommentResource::class;
}
```

- [ ] **Step 3: Cache**

```
php artisan filament:cache-components
```

- [ ] **Step 4: Commit**

```
git add app/Filament/Admin/Resources/NewsCommentResource.php app/Filament/Admin/Resources/NewsCommentResource/
git commit -m "feat(news): add NewsCommentResource moderation panel"
```

---

## Stage 3 — REST API

### Task 3.1: NewsCategoryResource transformer + controller + routes

**Files:**
- Create: `app/Interfaces/Http/Resources/News/NewsCategoryResource.php`
- Create: `app/Interfaces/Http/Controllers/V1/News/NewsCategoryController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Resource transformer**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\News;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'slug'        => $this->trans('slug'),
            'name'        => $this->trans('name'),
            'description' => $this->trans('description'),
            'icon'        => $this->icon,
            'color'       => $this->color,
            'news_count'  => $this->whenCounted('news'),
        ];
    }
}
```

- [ ] **Step 2: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\NewsCategory;
use App\Interfaces\Http\Resources\News\NewsCategoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class NewsCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = NewsCategory::query()
            ->where('is_active', true)
            ->with('translations')
            ->withCount('news')
            ->orderBy('sort_order')
            ->get();

        return NewsCategoryResource::collection($categories);
    }
}
```

- [ ] **Step 3: Route**

Modify `routes/api.php`. Inside the existing `Route::prefix('v1')->middleware(['tenant', 'locale', 'tenant.scope'])->group(...)` public-throttled section, add:

```php
        Route::get('/news-categories', [\App\Interfaces\Http\Controllers\V1\News\NewsCategoryController::class, 'index'])
             ->name('news-categories.index');
```

- [ ] **Step 4: Commit**

```
git add app/Interfaces/Http/Resources/News/NewsCategoryResource.php app/Interfaces/Http/Controllers/V1/News/NewsCategoryController.php routes/api.php
git commit -m "feat(news): add GET /api/v1/news-categories endpoint"
```

---

### Task 3.2: NewsResource transformer + NewsController + routes

**Files:**
- Create: `app/Interfaces/Http/Resources/News/NewsResource.php`
- Create: `app/Interfaces/Http/Controllers/V1/News/NewsController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Resource transformer**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\News;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = auth()->id();
        $isLiked = $userId
            ? $this->likes->contains(fn ($l) => $l->user_id === $userId)
            : false;

        return [
            'id'             => $this->id,
            'slug'           => $this->trans('slug'),
            'title'          => $this->trans('title'),
            'excerpt'        => $this->trans('excerpt'),
            'body'           => $this->trans('body'),
            'cover_url'      => $this->cover_url,
            'thumbnail_url'  => $this->thumbnail_url,
            'category'       => $this->whenLoaded('category', fn () => $this->category ? [
                'id'   => $this->category->id,
                'slug' => $this->category->trans('slug'),
                'name' => $this->category->trans('name'),
            ] : null),
            'author'         => $this->whenLoaded('author', fn () => $this->author ? [
                'id'   => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'view_count'     => $this->view_count,
            'like_count'     => $this->like_count,
            'comment_count'  => $this->comment_count,
            'is_liked'       => $isLiked,
            'is_featured'    => $this->is_featured,
            'published_at'   => $this->published_at?->toIso8601String(),
            'meta_title'     => $this->trans('meta_title'),
            'meta_description' => $this->trans('meta_description'),
            'translations'   => $this->when(
                $request->boolean('with_translations'),
                fn () => $this->translations->keyBy('locale')->map(fn ($t) => [
                    'title'   => $t->title,
                    'slug'    => $t->slug,
                    'excerpt' => $t->excerpt,
                    'body'    => $t->body,
                ])
            ),
        ];
    }
}
```

- [ ] **Step 2: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\News;
use App\Interfaces\Http\Resources\News\NewsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NewsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = News::query()
            ->live()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent();

        if ($categorySlug = $request->string('category')->toString()) {
            $query->whereHas('category.translations', fn ($q) => $q->where('slug', $categorySlug));
        }

        if ($search = $request->string('search')->toString()) {
            $query->whereHas('translations', fn ($q) => $q->where('title', 'ilike', "%{$search}%"));
        }

        return NewsResource::collection($query->paginate(12));
    }

    public function featured(): AnonymousResourceCollection
    {
        $rows = News::query()
            ->live()
            ->featured()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent()
            ->limit(5)
            ->get();

        return NewsResource::collection($rows);
    }

    public function latest(): AnonymousResourceCollection
    {
        $rows = News::query()
            ->live()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent()
            ->limit(10)
            ->get();

        return NewsResource::collection($rows);
    }

    public function show(string $slug): NewsResource
    {
        $news = $this->findBySlug($slug);
        $news->incrementViewCount();

        return new NewsResource($news);
    }

    public function related(string $slug): AnonymousResourceCollection
    {
        $news = $this->findBySlug($slug);

        $query = News::query()
            ->live()
            ->where('id', '!=', $news->id)
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent()
            ->limit(4);

        if ($news->news_category_id !== null) {
            $query->where('news_category_id', $news->news_category_id);
        }

        return NewsResource::collection($query->get());
    }

    private function findBySlug(string $slug): News
    {
        $news = News::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->live()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->first();

        if (! $news) {
            throw new NotFoundHttpException("News '{$slug}' not found.");
        }
        return $news;
    }
}
```

- [ ] **Step 3: Routes**

In `routes/api.php` inside the same public-throttled group (alongside `/news-categories`):

```php
        Route::prefix('news')->group(function (): void {
            Route::get('/', [\App\Interfaces\Http\Controllers\V1\News\NewsController::class, 'index'])->name('news.index');
            Route::get('/featured', [\App\Interfaces\Http\Controllers\V1\News\NewsController::class, 'featured'])->name('news.featured');
            Route::get('/latest', [\App\Interfaces\Http\Controllers\V1\News\NewsController::class, 'latest'])->name('news.latest');
            Route::get('/{slug}', [\App\Interfaces\Http\Controllers\V1\News\NewsController::class, 'show'])->name('news.show');
            Route::get('/{slug}/related', [\App\Interfaces\Http\Controllers\V1\News\NewsController::class, 'related'])->name('news.related');
        });
```

- [ ] **Step 4: Commit**

```
git add app/Interfaces/Http/Resources/News/NewsResource.php app/Interfaces/Http/Controllers/V1/News/NewsController.php routes/api.php
git commit -m "feat(news): public news endpoints (index/featured/latest/show/related)"
```

---

### Task 3.3: NewsCommentController + Resource + routes

**Files:**
- Create: `app/Interfaces/Http/Resources/News/NewsCommentResource.php`
- Create: `app/Interfaces/Http/Controllers/V1/News/NewsCommentController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Resource transformer**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\News;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'body'       => $this->body,
            'is_approved' => $this->is_approved,
            'parent_id'  => $this->parent_id,
            'author'     => $this->whenLoaded('user', fn () => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'replies_count' => $this->whenCounted('replies'),
            'replies'    => NewsCommentResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 2: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\News;
use App\Domain\News\Models\NewsComment;
use App\Interfaces\Http\Resources\News\NewsCommentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NewsCommentController extends Controller
{
    public function index(string $slug): AnonymousResourceCollection
    {
        $news = $this->findNewsBySlug($slug);

        $comments = NewsComment::query()
            ->where('news_id', $news->id)
            ->whereNull('parent_id')
            ->where('is_approved', true)
            ->with(['user', 'replies' => fn ($q) => $q->where('is_approved', true)->with('user')])
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->get();

        return NewsCommentResource::collection($comments);
    }

    public function store(Request $request, string $slug): NewsCommentResource
    {
        $request->validate([
            'body'      => 'required|string|min:2|max:2000',
            'parent_id' => 'nullable|integer|exists:news_comments,id',
        ]);

        $news = $this->findNewsBySlug($slug);

        $comment = NewsComment::create([
            'news_id'   => $news->id,
            'user_id'   => $request->user()->id,
            'parent_id' => $request->integer('parent_id') ?: null,
            'body'      => $request->string('body')->toString(),
            'is_approved' => false,
        ]);

        $comment->load('user');
        return new NewsCommentResource($comment);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = NewsComment::findOrFail($id);

        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not your comment.'], 403);
        }

        $comment->delete();
        return response()->json(['success' => true]);
    }

    private function findNewsBySlug(string $slug): News
    {
        $news = News::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->live()
            ->first();

        if (! $news) {
            throw new NotFoundHttpException("News '{$slug}' not found.");
        }
        return $news;
    }
}
```

- [ ] **Step 3: Routes**

In `routes/api.php`:

Add inside the public-throttled group (alongside other `/news` routes):

```php
            Route::get('/{slug}/comments', [\App\Interfaces\Http\Controllers\V1\News\NewsCommentController::class, 'index'])
                 ->name('news.comments.index');
```

Add inside the `Route::middleware(['auth:api', 'throttle:api'])->group(...)` section (where authenticated routes live):

```php
        Route::prefix('news')->group(function (): void {
            Route::post('/{slug}/comments', [\App\Interfaces\Http\Controllers\V1\News\NewsCommentController::class, 'store'])
                 ->name('news.comments.store');
            Route::delete('/comments/{id}', [\App\Interfaces\Http\Controllers\V1\News\NewsCommentController::class, 'destroy'])
                 ->name('news.comments.destroy');
        });
```

- [ ] **Step 4: Commit**

```
git add app/Interfaces/Http/Resources/News/NewsCommentResource.php app/Interfaces/Http/Controllers/V1/News/NewsCommentController.php routes/api.php
git commit -m "feat(news): comment endpoints (index public, store/destroy auth)"
```

---

### Task 3.4: NewsLikeController + routes

**Files:**
- Create: `app/Interfaces/Http/Controllers/V1/News/NewsLikeController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\News;
use App\Domain\News\Models\NewsLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NewsLikeController extends Controller
{
    public function toggle(Request $request, string $slug): JsonResponse
    {
        $news = News::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->live()
            ->first();

        if (! $news) {
            throw new NotFoundHttpException("News '{$slug}' not found.");
        }

        $userId = $request->user()->id;

        $existing = NewsLike::query()
            ->where('news_id', $news->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            NewsLike::create([
                'news_id' => $news->id,
                'user_id' => $userId,
            ]);
            $liked = true;
        }

        // Counters refresh from observer; refresh model to read updated like_count.
        $news->refresh();

        return response()->json([
            'liked'      => $liked,
            'like_count' => $news->like_count,
        ]);
    }
}
```

- [ ] **Step 2: Route**

In `routes/api.php` inside the auth+throttle group (alongside `news.comments.store`):

```php
            Route::post('/{slug}/like', [\App\Interfaces\Http\Controllers\V1\News\NewsLikeController::class, 'toggle'])
                 ->name('news.like.toggle');
```

- [ ] **Step 3: Smoke test all API routes**

```
php artisan route:list --path=news
```
Expected: 8+ routes (index/featured/latest/show/related/comments index/store/destroy + like + categories).

- [ ] **Step 4: Commit**

```
git add app/Interfaces/Http/Controllers/V1/News/NewsLikeController.php routes/api.php
git commit -m "feat(news): like toggle endpoint (POST /news/{slug}/like)"
```

---

### Task 3.5: Backend UI strings for new keys

**Files:**
- Modify: `lang/uz.json`
- Modify: `lang/ru.json`
- Modify: `lang/en.json`

- [ ] **Step 1: Add to lang/uz.json**

Add these keys (preserve existing entries):

```json
"news.title": "Yangiliklar",
"news.view_all": "Barcha yangiliklar",
"news.read_more": "Batafsil o'qish",
"news.published_at": "Nashr sanasi",
"news.author": "Muallif",
"news.like": "Yoqdi",
"news.comment": "Sharh",
"news.comment.placeholder": "Sharhingizni yozing...",
"news.comment.submit": "Yuborish",
"news.comment.moderation": "Sharhingiz moderatsiyaga yuborildi",
"news.comment.login_required": "Sharh yozish uchun tizimga kiring",
"news.no_results": "Hozircha yangiliklar yo'q",
"news.related": "O'xshash yangiliklar",
"news.reply": "Javob berish",
"news.load_more": "Ko'proq yuklash"
```

- [ ] **Step 2: Same set for lang/ru.json**

```json
"news.title": "Новости",
"news.view_all": "Все новости",
"news.read_more": "Читать далее",
"news.published_at": "Дата публикации",
"news.author": "Автор",
"news.like": "Нравится",
"news.comment": "Комментарий",
"news.comment.placeholder": "Напишите комментарий...",
"news.comment.submit": "Отправить",
"news.comment.moderation": "Комментарий отправлен на модерацию",
"news.comment.login_required": "Войдите чтобы комментировать",
"news.no_results": "Пока новостей нет",
"news.related": "Похожие новости",
"news.reply": "Ответить",
"news.load_more": "Загрузить ещё"
```

- [ ] **Step 3: Same set for lang/en.json**

```json
"news.title": "News",
"news.view_all": "View all",
"news.read_more": "Read more",
"news.published_at": "Published",
"news.author": "Author",
"news.like": "Like",
"news.comment": "Comment",
"news.comment.placeholder": "Write a comment...",
"news.comment.submit": "Submit",
"news.comment.moderation": "Your comment is pending review",
"news.comment.login_required": "Sign in to comment",
"news.no_results": "No news yet",
"news.related": "Related news",
"news.reply": "Reply",
"news.load_more": "Load more"
```

- [ ] **Step 4: Smoke test endpoint**

```
curl -H "X-Tenant-ID: 2" http://127.0.0.1:8000/api/v1/translations/uz | grep "news.title"
```
Expected: `"news.title":"Yangiliklar"`.

- [ ] **Step 5: Commit**

```
git add lang/uz.json lang/ru.json lang/en.json
git commit -m "feat(news): add UI string keys for news pages"
```

---

## Stage 4 — Frontend (Angular)

### Task 4.1: News model + safe-html pipe

**Files:**
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\core\models\news.model.ts`
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\shared\pipes\safe-html.pipe.ts`

- [ ] **Step 1: news.model.ts**

```typescript
export interface NewsAuthor {
  id: number;
  name: string;
  avatar_url?: string;
}

export interface NewsCategory {
  id: number;
  slug: string;
  name: string;
  description?: string;
  icon?: string;
  color?: string;
  news_count?: number;
}

export interface News {
  id: number;
  slug: string;
  title: string;
  excerpt: string | null;
  body: string;
  cover_url: string | null;
  thumbnail_url: string | null;
  category: NewsCategory | null;
  author: NewsAuthor | null;
  view_count: number;
  like_count: number;
  comment_count: number;
  is_liked: boolean;
  is_featured: boolean;
  published_at: string;
  meta_title?: string;
  meta_description?: string;
}

export interface NewsComment {
  id: number;
  body: string;
  parent_id: number | null;
  author: NewsAuthor | null;
  replies_count?: number;
  replies?: NewsComment[];
  created_at: string;
}

export interface NewsLikeResponse {
  liked: boolean;
  like_count: number;
}

export interface NewsListResponse {
  data: News[];
  meta?: { current_page: number; last_page: number; total: number };
}

export interface NewsCommentListResponse {
  data: NewsComment[];
}
```

- [ ] **Step 2: safe-html.pipe.ts**

```typescript
import { Pipe, PipeTransform, SecurityContext, inject } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';

/**
 * Renders pre-sanitized HTML (already cleaned server-side) as SafeHtml.
 * Use ONLY with content from trusted sources (admin-edited rich text).
 */
@Pipe({
  name: 'safeHtml',
  standalone: true,
})
export class SafeHtmlPipe implements PipeTransform {
  private readonly sanitizer = inject(DomSanitizer);

  transform(value: string | null | undefined): SafeHtml {
    if (!value) return '';
    return this.sanitizer.bypassSecurityTrustHtml(value);
  }
}
```

- [ ] **Step 3: Commit (no git in frontend repo — just save)**

The frontend folder isn't a git repo, so there's no commit step. Skip and continue.

---

### Task 4.2: 4 news services

**Files:**
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\core\services\news.service.ts`
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\core\services\news-category.service.ts`
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\core\services\news-comment.service.ts`
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\core\services\news-like.service.ts`
- Modify: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\core\services\index.ts`

- [ ] **Step 1: news.service.ts**

```typescript
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiService } from './api.service';
import { News, NewsListResponse } from '../models/news.model';

@Injectable({ providedIn: 'root' })
export class NewsService {
  private readonly api = inject(ApiService);

  list(params: { category?: string; search?: string; page?: number } = {}): Observable<NewsListResponse> {
    const cleaned: Record<string, string | number> = {};
    if (params.category) cleaned['category'] = params.category;
    if (params.search) cleaned['search'] = params.search;
    if (params.page) cleaned['page'] = params.page;
    return this.api.get<NewsListResponse>('/news', cleaned);
  }

  featured(): Observable<{ data: News[] }> {
    return this.api.get<{ data: News[] }>('/news/featured');
  }

  latest(): Observable<{ data: News[] }> {
    return this.api.get<{ data: News[] }>('/news/latest');
  }

  show(slug: string): Observable<{ data: News }> {
    return this.api.get<{ data: News }>(`/news/${slug}`);
  }

  related(slug: string): Observable<{ data: News[] }> {
    return this.api.get<{ data: News[] }>(`/news/${slug}/related`);
  }
}
```

- [ ] **Step 2: news-category.service.ts**

```typescript
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiService } from './api.service';
import { NewsCategory } from '../models/news.model';

@Injectable({ providedIn: 'root' })
export class NewsCategoryService {
  private readonly api = inject(ApiService);

  list(): Observable<{ data: NewsCategory[] }> {
    return this.api.get<{ data: NewsCategory[] }>('/news-categories');
  }
}
```

- [ ] **Step 3: news-comment.service.ts**

```typescript
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiService } from './api.service';
import { NewsComment, NewsCommentListResponse } from '../models/news.model';

@Injectable({ providedIn: 'root' })
export class NewsCommentService {
  private readonly api = inject(ApiService);

  list(slug: string): Observable<NewsCommentListResponse> {
    return this.api.get<NewsCommentListResponse>(`/news/${slug}/comments`);
  }

  create(slug: string, payload: { body: string; parent_id?: number }): Observable<{ data: NewsComment }> {
    return this.api.post<{ data: NewsComment }>(`/news/${slug}/comments`, payload);
  }

  delete(id: number): Observable<{ success: boolean }> {
    return this.api.delete<{ success: boolean }>(`/news/comments/${id}`);
  }
}
```

- [ ] **Step 4: news-like.service.ts**

```typescript
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiService } from './api.service';
import { NewsLikeResponse } from '../models/news.model';

@Injectable({ providedIn: 'root' })
export class NewsLikeService {
  private readonly api = inject(ApiService);

  toggle(slug: string): Observable<NewsLikeResponse> {
    return this.api.post<NewsLikeResponse>(`/news/${slug}/like`, {});
  }
}
```

- [ ] **Step 5: Update index.ts exports**

Modify `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\core\services\index.ts` — add at the bottom:

```typescript
export * from './news.service';
export * from './news-category.service';
export * from './news-comment.service';
export * from './news-like.service';
```

---

### Task 4.3: NewsCard + NewsHero components

**Files:**
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\news\components\news-card\news-card.component.ts`
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\news\components\news-hero\news-hero.component.ts`

- [ ] **Step 1: news-card.component.ts**

```typescript
import { Component, Input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { DatePipe } from '@angular/common';
import { News } from '../../../../core/models/news.model';

@Component({
  selector: 'app-news-card',
  standalone: true,
  imports: [RouterLink, DatePipe],
  template: `
    <a [routerLink]="['/news', news.slug]" class="news-card">
      <div class="news-card__image">
        @if (news.thumbnail_url || news.cover_url) {
          <img [src]="news.thumbnail_url ?? news.cover_url" [alt]="news.title" loading="lazy" />
        } @else {
          <div class="news-card__placeholder"><i class="fas fa-newspaper"></i></div>
        }
      </div>
      <div class="news-card__date">{{ news.published_at | date:'d-MMM, y' }}</div>
      <h3 class="news-card__title">{{ news.title }}</h3>
      @if (news.excerpt) { <p class="news-card__excerpt">{{ news.excerpt }}</p> }
    </a>
  `,
  styles: [`
    :host { display: block; }
    .news-card { display: block; text-decoration: none; color: inherit; }
    .news-card__image { aspect-ratio: 16 / 10; overflow: hidden; border-radius: 8px; background: #f3f4f6; }
    .news-card__image img { width: 100%; height: 100%; object-fit: cover; transition: transform .2s; }
    .news-card:hover .news-card__image img { transform: scale(1.03); }
    .news-card__placeholder { width: 100%; height: 100%; display: grid; place-items: center; color: #9ca3af; font-size: 32px; }
    .news-card__date { color: #6b7280; font-size: 12px; margin: 8px 0 4px; }
    .news-card__title { font-size: 14px; font-weight: 600; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin: 0; }
    .news-card__excerpt { font-size: 12px; color: #6b7280; margin: 4px 0 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  `],
})
export class NewsCardComponent {
  @Input({ required: true }) news!: News;
}
```

- [ ] **Step 2: news-hero.component.ts**

```typescript
import { Component, Input, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { DatePipe } from '@angular/common';
import { News } from '../../../../core/models/news.model';
import { TPipe } from '../../../../shared/pipes/t.pipe';

@Component({
  selector: 'app-news-hero',
  standalone: true,
  imports: [RouterLink, DatePipe, TPipe],
  template: `
    <article class="news-hero">
      <div class="news-hero__image">
        @if (news.cover_url) {
          <img [src]="news.cover_url" [alt]="news.title" />
        } @else {
          <div class="news-hero__placeholder"><i class="fas fa-newspaper"></i></div>
        }
      </div>
      <div class="news-hero__body">
        <div class="news-hero__date">{{ news.published_at | date:'d-MMM, y' }}</div>
        <h2 class="news-hero__title">{{ news.title }}</h2>
        @if (news.excerpt) { <p class="news-hero__excerpt">{{ news.excerpt }}</p> }
        <a [routerLink]="['/news', news.slug]" class="news-hero__cta">
          {{ 'news.read_more' | t }} <i class="fas fa-arrow-right"></i>
        </a>
      </div>
    </article>
  `,
  styles: [`
    :host { display: block; }
    .news-hero { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: stretch; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,.05); }
    @media (max-width: 768px) { .news-hero { grid-template-columns: 1fr; } }
    .news-hero__image { aspect-ratio: 16 / 10; }
    .news-hero__image img { width: 100%; height: 100%; object-fit: cover; }
    .news-hero__placeholder { width: 100%; height: 100%; display: grid; place-items: center; background: #f3f4f6; color: #9ca3af; font-size: 64px; }
    .news-hero__body { padding: 24px; display: flex; flex-direction: column; gap: 8px; }
    .news-hero__date { color: #6b7280; font-size: 14px; }
    .news-hero__title { font-size: 22px; font-weight: 700; margin: 0; line-height: 1.3; }
    .news-hero__excerpt { color: #4b5563; line-height: 1.6; margin: 8px 0; }
    .news-hero__cta { color: #16a34a; font-weight: 600; text-decoration: none; margin-top: auto; }
    .news-hero__cta:hover { text-decoration: underline; }
  `],
})
export class NewsHeroComponent {
  @Input({ required: true }) news!: News;
}
```

---

### Task 4.4: LikeButton + CommentList + CommentForm

**Files:**
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\news\components\like-button\like-button.component.ts`
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\news\components\comment-form\comment-form.component.ts`
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\news\components\comment-list\comment-list.component.ts`

- [ ] **Step 1: like-button.component.ts**

```typescript
import { Component, Input, OnInit, inject, signal } from '@angular/core';
import { NewsLikeService } from '../../../../core/services/news-like.service';
import { AuthService } from '../../../../core/services/auth.service';
import { TPipe } from '../../../../shared/pipes/t.pipe';
import { TranslateService } from '../../../../core/services/translate.service';

@Component({
  selector: 'app-like-button',
  standalone: true,
  imports: [TPipe],
  template: `
    <button
      type="button"
      class="like-btn"
      [class.liked]="liked()"
      [attr.title]="auth.isLoggedIn() ? '' : (translate.t('news.comment.login_required'))"
      (click)="onClick()"
    >
      <i [class]="liked() ? 'fas fa-heart' : 'far fa-heart'"></i>
      <span>{{ count() }}</span>
    </button>
  `,
  styles: [`
    .like-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 999px; background: transparent; cursor: pointer; font: inherit; color: inherit; transition: background .15s, color .15s; }
    .like-btn:hover { background: #fef2f2; color: #dc2626; }
    .like-btn.liked { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
    .like-btn i { font-size: 16px; }
  `],
})
export class LikeButtonComponent implements OnInit {
  @Input({ required: true }) slug!: string;
  @Input() initialLiked = false;
  @Input() initialCount = 0;

  private readonly likeService = inject(NewsLikeService);
  readonly auth = inject(AuthService);
  readonly translate = inject(TranslateService);

  readonly liked = signal(false);
  readonly count = signal(0);

  ngOnInit(): void {
    this.liked.set(this.initialLiked);
    this.count.set(this.initialCount);
  }

  onClick(): void {
    if (!this.auth.isLoggedIn()) return;
    this.likeService.toggle(this.slug).subscribe({
      next: (res) => {
        this.liked.set(res.liked);
        this.count.set(res.like_count);
      },
    });
  }
}
```

- [ ] **Step 2: comment-form.component.ts**

```typescript
import { Component, EventEmitter, Input, Output, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NewsCommentService } from '../../../../core/services/news-comment.service';
import { AuthService } from '../../../../core/services/auth.service';
import { TPipe } from '../../../../shared/pipes/t.pipe';
import { TranslateService } from '../../../../core/services/translate.service';
import { NewsComment } from '../../../../core/models/news.model';

@Component({
  selector: 'app-comment-form',
  standalone: true,
  imports: [FormsModule, TPipe],
  template: `
    @if (auth.isLoggedIn()) {
      <form class="comment-form" (ngSubmit)="submit()">
        <textarea
          [(ngModel)]="body"
          name="body"
          [placeholder]="translate.t('news.comment.placeholder')"
          rows="3"
          required
          minlength="2"
          maxlength="2000"
        ></textarea>
        <div class="comment-form__row">
          @if (status() === 'submitted') {
            <span class="comment-form__notice">{{ 'news.comment.moderation' | t }}</span>
          } @else {
            <button type="submit" [disabled]="status() === 'sending' || !body.trim()">
              {{ 'news.comment.submit' | t }}
            </button>
          }
        </div>
      </form>
    } @else {
      <div class="comment-form__login">
        {{ 'news.comment.login_required' | t }}
      </div>
    }
  `,
  styles: [`
    .comment-form { display: flex; flex-direction: column; gap: 8px; margin-bottom: 24px; }
    .comment-form textarea { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font: inherit; resize: vertical; }
    .comment-form__row { display: flex; justify-content: flex-end; align-items: center; gap: 12px; }
    .comment-form button { padding: 8px 16px; background: #16a34a; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font: inherit; }
    .comment-form button:disabled { opacity: .5; cursor: not-allowed; }
    .comment-form__notice { color: #16a34a; font-size: 14px; }
    .comment-form__login { padding: 12px 16px; background: #f9fafb; border: 1px dashed #e5e7eb; border-radius: 8px; text-align: center; color: #6b7280; }
  `],
})
export class CommentFormComponent {
  @Input({ required: true }) slug!: string;
  @Input() parentId: number | null = null;
  @Output() submitted = new EventEmitter<NewsComment>();

  private readonly commentService = inject(NewsCommentService);
  readonly auth = inject(AuthService);
  readonly translate = inject(TranslateService);

  body = '';
  readonly status = signal<'idle' | 'sending' | 'submitted' | 'error'>('idle');

  submit(): void {
    if (!this.body.trim() || this.status() === 'sending') return;

    this.status.set('sending');
    const payload: { body: string; parent_id?: number } = { body: this.body.trim() };
    if (this.parentId !== null) payload.parent_id = this.parentId;

    this.commentService.create(this.slug, payload).subscribe({
      next: (res) => {
        this.body = '';
        this.status.set('submitted');
        this.submitted.emit(res.data);
        setTimeout(() => this.status.set('idle'), 4000);
      },
      error: () => {
        this.status.set('error');
        setTimeout(() => this.status.set('idle'), 4000);
      },
    });
  }
}
```

- [ ] **Step 3: comment-list.component.ts**

```typescript
import { Component, Input } from '@angular/core';
import { DatePipe } from '@angular/common';
import { NewsComment } from '../../../../core/models/news.model';

@Component({
  selector: 'app-comment-list',
  standalone: true,
  imports: [DatePipe],
  template: `
    @if (comments && comments.length > 0) {
      <ul class="comment-list">
        @for (c of comments; track c.id) {
          <li class="comment">
            <div class="comment__head">
              <strong>{{ c.author?.name ?? 'Anonim' }}</strong>
              <span class="comment__date">{{ c.created_at | date:'d-MMM, y H:mm' }}</span>
            </div>
            <div class="comment__body">{{ c.body }}</div>
            @if (c.replies && c.replies.length > 0) {
              <ul class="comment-list comment-list--replies">
                @for (r of c.replies; track r.id) {
                  <li class="comment comment--reply">
                    <div class="comment__head">
                      <strong>{{ r.author?.name ?? 'Anonim' }}</strong>
                      <span class="comment__date">{{ r.created_at | date:'d-MMM, y H:mm' }}</span>
                    </div>
                    <div class="comment__body">{{ r.body }}</div>
                  </li>
                }
              </ul>
            }
          </li>
        }
      </ul>
    } @else {
      <p class="comment-list__empty">—</p>
    }
  `,
  styles: [`
    .comment-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 16px; }
    .comment { padding: 12px 16px; background: #f9fafb; border-radius: 8px; }
    .comment--reply { background: #fff; border: 1px solid #e5e7eb; }
    .comment__head { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; }
    .comment__date { color: #6b7280; }
    .comment__body { color: #1f2937; line-height: 1.5; white-space: pre-wrap; }
    .comment-list--replies { margin-top: 12px; margin-left: 24px; }
    .comment-list__empty { color: #9ca3af; text-align: center; padding: 16px; }
  `],
})
export class CommentListComponent {
  @Input({ required: true }) comments: NewsComment[] = [];
}
```

---

### Task 4.5: NewsListComponent (page)

**Files:**
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\news\pages\news-list\news-list.component.ts`

- [ ] **Step 1: Write the component**

```typescript
import { Component, OnInit, effect, inject, signal } from '@angular/core';
import { NewsService } from '../../../../core/services/news.service';
import { LocaleService } from '../../../../core/services/locale.service';
import { TPipe } from '../../../../shared/pipes/t.pipe';
import { NewsCardComponent } from '../../components/news-card/news-card.component';
import { NewsHeroComponent } from '../../components/news-hero/news-hero.component';
import { News } from '../../../../core/models/news.model';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-news-list',
  standalone: true,
  imports: [TPipe, NewsCardComponent, NewsHeroComponent, RouterLink],
  template: `
    <div class="news-page container">
      <header class="news-page__header">
        <h1>{{ 'news.title' | t }}</h1>
        <a routerLink="/news" class="news-page__view-all">{{ 'news.view_all' | t }} ›</a>
      </header>

      @if (hero(); as h) {
        <app-news-hero [news]="h" />
      }

      <div class="news-grid">
        @for (n of grid(); track n.id) {
          <app-news-card [news]="n" />
        }
      </div>

      @if (grid().length === 0 && !loading()) {
        <div class="news-empty">{{ 'news.no_results' | t }}</div>
      }

      @if (hasMore()) {
        <div class="news-more">
          <button (click)="loadMore()" [disabled]="loading()">{{ 'news.load_more' | t }}</button>
        </div>
      }
    </div>
  `,
  styles: [`
    .news-page { padding: 24px 0; }
    .news-page__header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 24px; }
    .news-page__header h1 { font-size: 28px; font-weight: 700; margin: 0; }
    .news-page__view-all { color: #16a34a; text-decoration: none; font-weight: 600; }
    .news-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 24px; margin-top: 32px; }
    .news-empty { padding: 60px 20px; text-align: center; color: #6b7280; }
    .news-more { text-align: center; margin-top: 32px; }
    .news-more button { padding: 10px 24px; background: transparent; border: 1px solid #16a34a; color: #16a34a; border-radius: 999px; font: inherit; cursor: pointer; }
    .news-more button:disabled { opacity: .5; cursor: not-allowed; }
  `],
})
export class NewsListComponent implements OnInit {
  private readonly newsService = inject(NewsService);
  private readonly localeService = inject(LocaleService);

  readonly hero = signal<News | null>(null);
  readonly grid = signal<News[]>([]);
  readonly loading = signal(false);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);

  constructor() {
    effect(() => {
      this.localeService.current();
      this.reload();
    });
  }

  ngOnInit(): void {
    this.reload();
  }

  hasMore(): boolean {
    return this.currentPage() < this.lastPage();
  }

  loadMore(): void {
    if (this.loading() || !this.hasMore()) return;
    const next = this.currentPage() + 1;
    this.loading.set(true);
    this.newsService.list({ page: next }).subscribe({
      next: (res) => {
        this.grid.update((g) => [...g, ...res.data]);
        this.currentPage.set(res.meta?.current_page ?? next);
        this.lastPage.set(res.meta?.last_page ?? next);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  private reload(): void {
    this.loading.set(true);
    this.newsService.featured().subscribe({
      next: (res) => this.hero.set(res.data[0] ?? null),
    });
    this.newsService.list({ page: 1 }).subscribe({
      next: (res) => {
        this.grid.set(res.data);
        this.currentPage.set(res.meta?.current_page ?? 1);
        this.lastPage.set(res.meta?.last_page ?? 1);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
```

---

### Task 4.6: NewsDetailComponent (page)

**Files:**
- Create: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\news\pages\news-detail\news-detail.component.ts`

- [ ] **Step 1: Write the component**

```typescript
import { Component, OnInit, effect, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { CommonModule, DatePipe } from '@angular/common';
import { NewsService } from '../../../../core/services/news.service';
import { NewsCommentService } from '../../../../core/services/news-comment.service';
import { LocaleService } from '../../../../core/services/locale.service';
import { TPipe } from '../../../../shared/pipes/t.pipe';
import { SafeHtmlPipe } from '../../../../shared/pipes/safe-html.pipe';
import { LikeButtonComponent } from '../../components/like-button/like-button.component';
import { CommentListComponent } from '../../components/comment-list/comment-list.component';
import { CommentFormComponent } from '../../components/comment-form/comment-form.component';
import { NewsCardComponent } from '../../components/news-card/news-card.component';
import { News, NewsComment } from '../../../../core/models/news.model';

@Component({
  selector: 'app-news-detail',
  standalone: true,
  imports: [
    CommonModule, RouterLink, DatePipe, TPipe, SafeHtmlPipe,
    LikeButtonComponent, CommentListComponent, CommentFormComponent, NewsCardComponent,
  ],
  template: `
    @if (news(); as n) {
      <article class="news-detail container">
        <div class="news-detail__hero">
          @if (n.cover_url) { <img [src]="n.cover_url" [alt]="n.title" /> }
        </div>

        <header class="news-detail__header">
          @if (n.category) {
            <a [routerLink]="['/news']" [queryParams]="{ category: n.category.slug }" class="news-detail__cat">
              {{ n.category.name }}
            </a>
          }
          <h1>{{ n.title }}</h1>
          <div class="news-detail__meta">
            @if (n.author) { <span>{{ 'news.author' | t }}: {{ n.author.name }}</span> · }
            <span>{{ n.published_at | date:'d-MMM, y' }}</span>
            · <span>{{ n.view_count }} 👁</span>
          </div>
        </header>

        @if (n.excerpt) { <p class="news-detail__excerpt">{{ n.excerpt }}</p> }

        <div class="news-detail__body" [innerHTML]="n.body | safeHtml"></div>

        <div class="news-detail__actions">
          <app-like-button [slug]="n.slug" [initialLiked]="n.is_liked" [initialCount]="n.like_count" />
        </div>

        <section class="news-detail__comments">
          <h2>{{ 'news.comment' | t }} ({{ n.comment_count }})</h2>
          <app-comment-form [slug]="n.slug" (submitted)="onCommentSubmitted($event)" />
          <app-comment-list [comments]="comments()" />
        </section>

        @if (related().length > 0) {
          <section class="news-detail__related">
            <h2>{{ 'news.related' | t }}</h2>
            <div class="news-detail__related-grid">
              @for (r of related(); track r.id) {
                <app-news-card [news]="r" />
              }
            </div>
          </section>
        }
      </article>
    } @else if (notFound()) {
      <div class="news-empty container">{{ 'news.no_results' | t }}</div>
    }
  `,
  styles: [`
    .news-detail { padding: 24px 0; max-width: 900px; margin: 0 auto; }
    .news-detail__hero { aspect-ratio: 21 / 9; overflow: hidden; border-radius: 12px; background: #f3f4f6; }
    .news-detail__hero img { width: 100%; height: 100%; object-fit: cover; }
    .news-detail__header { margin: 24px 0 16px; }
    .news-detail__cat { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #f0fdf4; color: #16a34a; font-size: 12px; text-decoration: none; margin-bottom: 12px; }
    .news-detail__header h1 { font-size: 32px; font-weight: 700; line-height: 1.2; margin: 8px 0; }
    .news-detail__meta { color: #6b7280; font-size: 14px; display: flex; gap: 6px; }
    .news-detail__excerpt { font-size: 18px; color: #4b5563; font-style: italic; margin: 16px 0; }
    .news-detail__body { font-size: 16px; line-height: 1.7; color: #1f2937; }
    .news-detail__body h2, .news-detail__body h3 { margin: 24px 0 12px; }
    .news-detail__body p { margin: 12px 0; }
    .news-detail__body img { max-width: 100%; border-radius: 8px; margin: 16px 0; }
    .news-detail__actions { margin: 24px 0; }
    .news-detail__comments { margin: 48px 0; }
    .news-detail__comments h2 { font-size: 20px; margin-bottom: 16px; }
    .news-detail__related { margin: 48px 0; }
    .news-detail__related h2 { font-size: 20px; margin-bottom: 16px; }
    .news-detail__related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
    .news-empty { padding: 60px 20px; text-align: center; color: #6b7280; }
  `],
})
export class NewsDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly newsService = inject(NewsService);
  private readonly commentService = inject(NewsCommentService);
  private readonly localeService = inject(LocaleService);

  readonly news = signal<News | null>(null);
  readonly comments = signal<NewsComment[]>([]);
  readonly related = signal<News[]>([]);
  readonly notFound = signal(false);

  constructor() {
    effect(() => {
      this.localeService.current();
      const slug = this.route.snapshot.paramMap.get('slug');
      if (slug) this.load(slug);
    });
  }

  ngOnInit(): void {
    this.route.paramMap.subscribe((p) => {
      const slug = p.get('slug');
      if (slug) this.load(slug);
    });
  }

  onCommentSubmitted(_comment: NewsComment): void {
    // Reload comments — the new one is pending so won't appear yet.
    const slug = this.news()?.slug;
    if (slug) this.loadComments(slug);
  }

  private load(slug: string): void {
    this.notFound.set(false);
    this.newsService.show(slug).subscribe({
      next: (res) => this.news.set(res.data),
      error: () => this.notFound.set(true),
    });
    this.loadComments(slug);
    this.newsService.related(slug).subscribe({
      next: (res) => this.related.set(res.data),
    });
  }

  private loadComments(slug: string): void {
    this.commentService.list(slug).subscribe({
      next: (res) => this.comments.set(res.data),
    });
  }
}
```

---

### Task 4.7: Routes + Layout navigation update

**Files:**
- Modify: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\app.routes.ts`
- Modify: `D:\Projects\Angular\Library\SecondFront\SecondFront\src\app\features\layout\layout.component.ts`

- [ ] **Step 1: Add news routes**

Read `app.routes.ts` first to see current structure. Then add inside the `''` parent's `children:` array (after `books/:slug` route):

```typescript
      {
        path: 'news',
        loadComponent: () => import('./features/news/pages/news-list/news-list.component').then(m => m.NewsListComponent),
      },
      {
        path: 'news/:slug',
        loadComponent: () => import('./features/news/pages/news-detail/news-detail.component').then(m => m.NewsDetailComponent),
      },
```

- [ ] **Step 2: Update LayoutComponent navigation**

Modify the existing nav link for "Yangiliklar" in `layout.component.ts`. Find:

```html
<li><a>{{ 'news' | t }}</a></li>
```

Replace with:

```html
<li><a routerLink="/news" routerLinkActive="active">{{ 'news.title' | t }}</a></li>
```

(Note: spec uses `news.title` key for the nav label so it stays distinct from other `news.*` keys.)

- [ ] **Step 3: Verify build**

Run from frontend root:
```
cd D:\Projects\Angular\Library\SecondFront\SecondFront
npm run build
```
Expected: build succeeds. If TS errors appear, fix them inline (most likely missing imports).

---

## Stage 5 — Manual smoke test on dev

This stage replaces formal automated tests for now (per project preference). The plan covers a manual end-to-end walkthrough that catches the regressions automated tests would catch.

### Task 5.1: End-to-end manual smoke

- [ ] **Step 1: Backend running**

```
cd d:/OSPanel/home/kutubxona.uz
php artisan serve
```

- [ ] **Step 2: Frontend running**

```
cd D:\Projects\Angular\Library\SecondFront\SecondFront
npm start
```

- [ ] **Step 3: Create a news category in admin**

Open `http://kutubxona.uz/admin/news-categories/create`. Fill Uzbek tab: `name = "Maktab yangiliklari"`, save. Open the row and add Russian translation: `name = "Школьные новости"`. Save.

Verify in DB:
```
php artisan tinker --execute="echo \App\Domain\News\Models\NewsCategoryTranslation::count();"
```
Expected: `2`.

- [ ] **Step 4: Create a news article**

Open `http://kutubxona.uz/admin/news/create`. Select category, set status = "Nashr qilingan", toggle `is_featured` on. Fill Uzbek tab: title, excerpt, body (rich text). Save. Upload a cover image.

- [ ] **Step 5: Frontend list page**

Open `http://kutubxona.uz:4200/news` (or whatever port Angular runs on). Expected: hero shows the featured article, grid shows the same article (just one). Date formatted. Cover image renders.

- [ ] **Step 6: Frontend detail page**

Click the hero. URL becomes `/news/<slug>`. Body renders as HTML.

- [ ] **Step 7: Locale switch**

Click the language selector → switch to Russian. Both list and detail pages should refresh and show Russian title/body (if you added Russian translation in step 3 for the category but not for the news, the news will fall back to default locale).

- [ ] **Step 8: Like**

Sign in (existing account). On detail page, click like button. Number increments. Click again → decrements. Reload page → state persists.

- [ ] **Step 9: Comment**

Write a comment, submit. Toast shows "Sharhingiz moderatsiyaga yuborildi". Reload — comment is NOT visible yet (pending).

- [ ] **Step 10: Admin approves**

Open `http://kutubxona.uz/admin/news-comments`. The pending comment appears. Click "Tasdiqlash". Reload the frontend detail page — comment now visible.

- [ ] **Step 11: Counter sanity**

```
php artisan tinker --execute="
\$n = \App\Domain\News\Models\News::withoutGlobalScopes()->first();
echo 'cached: ' . \$n->like_count . '/' . \$n->comment_count . ' actual: ' . \$n->likes()->count() . '/' . \$n->approvedComments()->count();
"
```
Expected: cached and actual counts match.

If any step fails, fix the underlying issue before continuing.

---

## Final verification checklist

- [ ] All 6 migrations applied: `php artisan migrate:status | tail -8`
- [ ] All 6 models load: see Task 1.14 smoke test
- [ ] Filament admin shows "Yangiliklar" group with 3 resources
- [ ] Creating a news with 2-language translation persists both
- [ ] `GET /api/v1/news` returns paginated results with locale-aware titles
- [ ] `GET /api/v1/news-categories` returns the categories
- [ ] `POST /api/v1/news/{slug}/like` toggles correctly
- [ ] `POST /api/v1/news/{slug}/comments` creates pending; visible after admin approves
- [ ] Frontend `/news` page renders hero + grid
- [ ] Frontend `/news/:slug` renders body, like button, comment form
- [ ] Locale switch refetches news and translates titles

---

## Production deploy

After all tasks complete and smoke test passes locally:

```bash
# Local:
git push origin main

# Server:
cd /home/sehrlikitoblar/admin.sehrlikitoblar.uz
git pull origin main
php artisan migrate --force
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan filament:cache-components
php artisan config:cache

# Frontend:
cd D:\Projects\Angular\Library\SecondFront\SecondFront
npm run build
# Deploy dist/ to your frontend hosting (whatever process you use)
```
