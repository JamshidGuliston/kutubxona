<?php

declare(strict_types=1);

namespace App\Domain\AudioBook\Models;

use App\Domain\Book\Models\Author;
use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\Category;
use App\Domain\Book\Models\Publisher;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $book_id
 * @property int|null $author_id
 * @property int|null $publisher_id
 * @property int|null $category_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string|null $narrator
 * @property string $language
 * @property int|null $published_year
 * @property string|null $cover_image
 * @property string|null $cover_thumbnail
 * @property int|null $total_duration
 * @property int $total_chapters
 * @property string $status
 * @property bool $is_featured
 * @property bool $is_free
 * @property float|null $price
 * @property int $listen_count
 * @property float|null $average_rating
 * @property int $rating_count
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $published_at
 * @property int|null $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class AudioBook extends Model
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    protected $table = 'audiobooks';

    protected $fillable = [
        'tenant_id',
        'book_id',
        'author_id',
        'publisher_id',
        'category_id',
        'title',
        'slug',
        'description',
        'narrator',
        'language',
        'published_year',
        'cover_image',
        'cover_thumbnail',
        'total_duration',
        'total_chapters',
        'status',
        'is_featured',
        'is_free',
        'price',
        'listen_count',
        'average_rating',
        'rating_count',
        'metadata',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'is_featured'    => 'boolean',
        'is_free'        => 'boolean',
        'price'          => 'decimal:2',
        'total_duration' => 'integer',
        'total_chapters' => 'integer',
        'listen_count'   => 'integer',
        'average_rating' => 'decimal:2',
        'rating_count'   => 'integer',
        'published_year' => 'integer',
        'metadata'       => 'array',
        'published_at'   => 'datetime',
    ];

    protected $hidden = ['tenant_id', 'created_by'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $audiobook): void {
            if (app()->has('tenant') && empty($audiobook->tenant_id)) {
                $audiobook->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($audiobook->created_by)) {
                $audiobook->created_by = auth()->id();
            }
        });

        static::saved(function (self $audiobook): void {
            // Keep chapter count in sync
            $audiobook->updateQuietly([
                'total_chapters' => $audiobook->chapters()->count(),
                'total_duration' => $audiobook->chapters()->sum('duration'),
            ]);
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function linkedBook(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
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

    public function chapters(): HasMany
    {
        return $this->hasMany(AudioBookChapter::class, 'audiobook_id')
            ->orderBy('chapter_number');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(\App\Domain\Reading\Models\Review::class, 'audiobook_id')
            ->where('is_approved', true);
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

    public function scopeByLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    protected function totalDurationFormatted(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $seconds = $this->total_duration ?? 0;
                $hours   = intdiv($seconds, 3600);
                $minutes = intdiv($seconds % 3600, 60);
                return sprintf('%dh %dm', $hours, $minutes);
            }
        );
    }

    protected function coverUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->cover_image
                ? (str_starts_with($this->cover_image, 'http')
                    ? $this->cover_image
                    : config('filesystems.disks.s3.url') . '/' . $this->cover_image)
                : null
        );
    }

    // ─── Scout ──────────────────────────────────────────────────────────────────

    public function toSearchableArray(): array
    {
        return [
            'id'          => $this->id,
            'tenant_id'   => $this->tenant_id,
            'title'       => $this->title,
            'description' => $this->description,
            'narrator'    => $this->narrator,
            'author_name' => $this->author?->name,
            'language'    => $this->language,
            'status'      => $this->status,
        ];
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function incrementListenCount(): void
    {
        $this->increment('listen_count');
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
