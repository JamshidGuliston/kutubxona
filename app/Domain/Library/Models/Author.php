<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Book\Models\Book;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $bio
 * @property string|null $photo_path
 * @property string|null $birth_date
 * @property string|null $nationality
 * @property string|null $website
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Author extends Model
{
    use HasFactory;
    use HasTenantScope;
    use SoftDeletes;

    protected $table = 'authors';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'bio',
        'photo_path',
        'birth_date',
        'death_date',
        'directions',
        'nationality',
        'website',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'birth_date'  => 'date',
        'death_date'  => 'date',
        'directions'  => 'array',
    ];

    protected $hidden = ['tenant_id'];

    protected static function booted(): void
    {
        static::creating(function (self $author): void {
            if (empty($author->slug)) {
                $author->slug = static::generateUniqueSlug($author->name, $author->tenant_id);
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'author_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereFullText('name', $term)
            ->orWhere('name', 'like', "%{$term}%");
    }

    public function scopeByNationality(Builder $query, string $nationality): Builder
    {
        return $query->where('nationality', $nationality);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    protected function photoUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! $this->photo_path) {
                    return null;
                }
                return str_starts_with($this->photo_path, 'http')
                    ? $this->photo_path
                    : config('filesystems.disks.s3.url') . '/' . $this->photo_path;
            }
        );
    }

    // ─── Static helpers ──────────────────────────────────────────────────────────

    public static function generateUniqueSlug(string $name, int $tenantId): string
    {
        $base  = Str::slug($name);
        $slug  = $base;
        $count = 1;

        while (
            static::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$base}-{$count}";
            $count++;
        }

        return $slug;
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function getBookCount(): int
    {
        return $this->books()->published()->count();
    }
}
