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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $color
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $lft
 * @property int|null $rgt
 * @property int $depth
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class Category extends Model
{
    use HasFactory;
    use HasTenantScope;

    protected $table = 'categories';

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'sort_order',
        'is_active',
        'lft',
        'rgt',
        'depth',
    ];

    protected $casts = [
        'parent_id'  => 'integer',
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
        'lft'        => 'integer',
        'rgt'        => 'integer',
        'depth'      => 'integer',
    ];

    protected $hidden = ['tenant_id', 'lft', 'rgt'];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if (empty($category->slug)) {
                $category->slug = static::generateUniqueSlug($category->name, $category->tenant_id);
            }
            $category->depth = $category->parent_id
                ? (static::withoutGlobalScope(TenantScope::class)
                    ->find($category->parent_id)?->depth ?? 0) + 1
                : 0;
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

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
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    /**
     * Books linked via the book_categories pivot (many-to-many).
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_categories')
            ->withTimestamps();
    }

    /**
     * Books where this category is the primary category.
     */
    public function primaryBooks(): HasMany
    {
        return $this->hasMany(Book::class, 'category_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isLeaf(): bool
    {
        return $this->children()->count() === 0;
    }

    /**
     * Return this category + all descendant IDs (for inclusive queries).
     */
    public function getDescendantIds(): Collection
    {
        $ids = collect([$this->id]);

        $this->children->each(function (self $child) use ($ids): void {
            $ids->push(...$child->getDescendantIds());
        });

        return $ids->unique();
    }

    public function getBreadcrumb(): Collection
    {
        $breadcrumb = collect();

        $category = $this;
        while ($category) {
            $breadcrumb->prepend(['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug]);
            $category = $category->parent_id
                ? static::withoutGlobalScope(TenantScope::class)->find($category->parent_id)
                : null;
        }

        return $breadcrumb;
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
