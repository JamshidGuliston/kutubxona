<?php

declare(strict_types=1);

namespace App\Domain\Localization\Models;

use App\Domain\Localization\Observers\TenantLanguageObserver;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property string|null $flag_emoji
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
#[ObservedBy([TenantLanguageObserver::class])]
final class TenantLanguage extends Model
{
    protected $table = 'tenant_languages';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'native_name',
        'flag_emoji',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCurrentTenant(Builder $query): Builder
    {
        $tenantId = app()->has('tenant') ? app('tenant')->id : null;
        return $tenantId ? $query->where('tenant_id', $tenantId) : $query;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }
}
