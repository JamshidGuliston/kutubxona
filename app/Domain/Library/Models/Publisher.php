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
 * @property string|null $description
 * @property string|null $website
 * @property string|null $logo_path
 * @property int|null $founded_year
 * @property string|null $country
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Publisher extends Model
{
    use HasFactory;
    use HasTenantScope;
    use SoftDeletes;

    protected $table = 'publishers';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'website',
        'logo_path',
        'founded_year',
        'country',
        'metadata',
    ];

    protected $casts = [
        'founded_year' => 'integer',
        'metadata'     => 'array',
    ];

    protected $hidden = ['tenant_id'];

    protected static function booted(): void
    {
        static::creating(function (self $publisher): void {
            if (empty($publisher->slug)) {
                $publisher->slug = static::generateUniqueSlug($publisher->name, $publisher->tenant_id);
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
        return $this->hasMany(Book::class, 'publisher_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('name', 'like', "%{$term}%");
    }

    public function scopeByCountry(Builder $query, string $country): Builder
    {
        return $query->where('country', $country);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! $this->logo_path) {
                    return null;
                }
                return str_starts_with($this->logo_path, 'http')
                    ? $this->logo_path
                    : config('filesystems.disks.s3.url') . '/' . $this->logo_path;
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
}
