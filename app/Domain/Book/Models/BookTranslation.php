<?php

declare(strict_types=1);

namespace App\Domain\Book\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $book_id
 * @property string $locale
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $description
 * @property string $slug
 * @property string|null $meta_title
 * @property string|null $meta_description
 */
final class BookTranslation extends Model
{
    protected $table = 'book_translations';

    protected $fillable = [
        'tenant_id',
        'book_id',
        'locale',
        'title',
        'subtitle',
        'description',
        'slug',
        'meta_title',
        'meta_description',
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

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
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
