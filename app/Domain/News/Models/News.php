<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Localization\Contracts\HasTranslations as HasTranslationsContract;
use App\Domain\Localization\Traits\HasTranslations;
use App\Domain\News\Enums\NewsStatus;
use App\Domain\News\Observers\NewsObserver;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
#[ObservedBy([NewsObserver::class])]
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
