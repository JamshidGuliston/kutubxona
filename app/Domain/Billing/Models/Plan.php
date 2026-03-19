<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property float $price_monthly
 * @property float|null $price_yearly
 * @property int $max_users
 * @property int $max_books
 * @property int $storage_quota      bytes
 * @property array|null $features
 * @property bool $is_active
 * @property int $sort_order
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class Plan extends Model
{
    use HasFactory;

    protected $table = 'plans';

    protected $fillable = [
        'name',
        'slug',
        'price_monthly',
        'price_yearly',
        'max_users',
        'max_books',
        'storage_quota',
        'features',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly'  => 'decimal:2',
        'max_users'     => 'integer',
        'max_books'     => 'integer',
        'storage_quota' => 'integer',
        'features'      => 'array',
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(\App\Domain\Tenant\Models\Tenant::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function getStorageQuotaMb(): float
    {
        return round($this->storage_quota / 1024 / 1024, 2);
    }

    public function isFeatureEnabled(string $feature): bool
    {
        return (bool) data_get($this->features, $feature, false);
    }
}
