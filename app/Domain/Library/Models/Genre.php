<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Book\Models\Book;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class Genre extends Model
{
    use HasFactory;
    use HasTenantScope;

    protected $table = 'genres';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
    ];

    protected $hidden = ['tenant_id'];

    protected static function booted(): void
    {
        static::creating(function (self $genre): void {
            if (empty($genre->slug)) {
                $genre->slug = static::generateUniqueSlug($genre->name, $genre->tenant_id);
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_genres');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('name', 'like', "%{$term}%");
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
