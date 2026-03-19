<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $domain
 * @property string $type
 * @property bool $is_primary
 * @property string $ssl_status
 * @property \Carbon\Carbon|null $verified_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class TenantDomain extends Model
{
    protected $table = 'tenant_domains';

    protected $fillable = [
        'tenant_id',
        'domain',
        'type',
        'is_primary',
        'ssl_status',
        'verified_at',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'verified_at' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('type', 'custom');
    }

    // ─── Business Logic ─────────────────────────────────────────────────────────

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isCustomDomain(): bool
    {
        return $this->type === 'custom';
    }

    public function isSubdomain(): bool
    {
        return $this->type === 'subdomain';
    }

    public function getFullUrl(): string
    {
        return 'https://' . $this->domain;
    }
}
