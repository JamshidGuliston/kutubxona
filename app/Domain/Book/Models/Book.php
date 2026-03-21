<?php

declare(strict_types=1);

namespace App\Domain\Book\Models;

use App\Domain\Library\Models\Author;
use App\Domain\Library\Models\Category;
use App\Domain\Library\Models\Publisher;
use App\Domain\Library\Models\Tag;
use App\Domain\Reading\Models\Bookmark;
use App\Domain\Reading\Models\Highlight;
use App\Domain\Reading\Models\ReadingProgress;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $author_id
 * @property int|null $publisher_id
 * @property int|null $category_id
 * @property string $title
 * @property string $slug
 * @property string|null $subtitle
 * @property string|null $description
 * @property string|null $isbn
 * @property string|null $isbn13
 * @property string $language
 * @property int|null $published_year
 * @property string|null $edition
 * @property int|null $pages
 * @property string|null $cover_image
 * @property string|null $cover_thumbnail
 * @property string $status
 * @property bool $is_featured
 * @property bool $is_downloadable
 * @property bool $is_free
 * @property float|null $price
 * @property int $download_count
 * @property int $view_count
 * @property float|null $average_rating
 * @property int $rating_count
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $published_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Book extends Model
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    protected $table = 'books';

    protected $fillable = [
        'tenant_id',
        'author_id',
        'publisher_id',
        'category_id',
        'title',
        'slug',
        'subtitle',
        'description',
        'isbn',
        'isbn13',
        'language',
        'published_year',
        'edition',
        'pages',
        'cover_image',
        'cover_thumbnail',
        'pdf_path',
        'audio_path',
        'status',
        'is_featured',
        'is_downloadable',
        'is_free',
        'price',
        'download_count',
        'view_count',
        'average_rating',
        'rating_count',
        'metadata',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_featured'    => 'boolean',
        'is_downloadable'=> 'boolean',
        'is_free'        => 'boolean',
        'price'          => 'decimal:2',
        'download_count' => 'integer',
        'view_count'     => 'integer',
        'average_rating' => 'decimal:2',
        'rating_count'   => 'integer',
        'pages'          => 'integer',
        'published_year' => 'integer',
        'metadata'       => 'array',
        'published_at'   => 'datetime',
    ];

    protected $hidden = [
        'tenant_id',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['cover_url', 'thumbnail_url'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $book): void {
            if (app()->has('tenant') && empty($book->tenant_id)) {
                $book->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($book->created_by)) {
                $book->created_by = auth()->id();
            }
            if (empty($book->slug) && !empty($book->title)) {
                $base = \Illuminate\Support\Str::slug($book->title);
                $slug = $base;
                $i = 1;
                while (static::withoutGlobalScopes()->where('tenant_id', $book->tenant_id)->where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $i++;
                }
                $book->slug = $slug;
            }
        });

        static::updating(function (self $book): void {
            if (auth()->check()) {
                $book->updated_by = auth()->id();
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'publisher_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'book_categories')
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'book_tags');
    }

    public function files(): HasMany
    {
        return $this->hasMany(BookFile::class, 'book_id');
    }

    public function primaryFile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BookFile::class, 'book_id')
            ->where('is_primary', true)
            ->where('processing_status', 'ready');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(\App\Domain\Library\Models\Review::class, 'book_id')
            ->where('is_approved', true);
    }

    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class, 'book_id');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'book_id');
    }

    public function highlights(): HasMany
    {
        return $this->hasMany(Highlight::class, 'book_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeFree(Builder $query): Builder
    {
        return $query->where('is_free', true);
    }

    public function scopeDownloadable(Builder $query): Builder
    {
        return $query->where('is_downloadable', true);
    }

    public function scopeByLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->orderByDesc('download_count');
    }

    public function scopeNewArrivals(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereFullText(['title', 'description'], $term);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    protected function coverUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (!$this->cover_image) return null;
                return str_starts_with($this->cover_image, 'http')
                    ? $this->cover_image
                    : \Illuminate\Support\Facades\Storage::disk('uploads')->url($this->cover_image);
            }
        );
    }

    protected function thumbnailUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (!$this->cover_thumbnail) return null;
                return str_starts_with($this->cover_thumbnail, 'http')
                    ? $this->cover_thumbnail
                    : \Illuminate\Support\Facades\Storage::disk('uploads')->url($this->cover_thumbnail);
            }
        );
    }

    // ─── Laravel Scout ───────────────────────────────────────────────────────────

    public function toSearchableArray(): array
    {
        return [
            'id'            => $this->id,
            'tenant_id'     => $this->tenant_id,
            'title'         => $this->title,
            'description'   => $this->description,
            'author_name'   => $this->author?->name,
            'publisher_name'=> $this->publisher?->name,
            'language'      => $this->language,
            'status'        => $this->status,
            'tags'          => $this->tags->pluck('name')->toArray(),
        ];
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function incrementDownloadCount(): void
    {
        $this->increment('download_count');
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function recalculateRating(): void
    {
        $stats = $this->reviews()
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
            ->first();

        $this->update([
            'average_rating' => round((float) $stats->avg_rating, 2),
            'rating_count'   => (int) $stats->total,
        ]);
    }
}
