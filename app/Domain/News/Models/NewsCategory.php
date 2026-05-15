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
