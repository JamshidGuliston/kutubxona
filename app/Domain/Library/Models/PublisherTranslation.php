<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class PublisherTranslation extends Model
{
    protected $table = 'publisher_translations';

    protected $fillable = ['tenant_id', 'publisher_id', 'locale', 'name', 'description', 'slug'];

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

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'publisher_id');
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
