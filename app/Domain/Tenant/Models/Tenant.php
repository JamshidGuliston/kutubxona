<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Models;

use App\Domain\Book\Models\Book;
use App\Domain\User\Models\User;
use App\Domain\Tenant\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property int|null $plan_id
 * @property array|null $settings
 * @property array|null $metadata
 * @property int $storage_quota
 * @property int $storage_used
 * @property int $max_users
 * @property int $max_books
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property \Carbon\Carbon|null $suspended_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Tenant extends Model
{
    use SoftDeletes;

    protected $table = 'tenants';

    protected $fillable = [
        'ulid',
        'name',
        'slug',
        'status',
        'plan_id',
        'settings',
        'metadata',
        'storage_quota',
        'storage_used',
        'max_users',
        'max_books',
        'trial_ends_at',
        'suspended_at',
    ];

    protected $casts = [
        'status'        => TenantStatus::class,
        'settings'      => 'array',
        'metadata'      => 'array',
        'storage_quota' => 'integer',
        'storage_used'  => 'integer',
        'max_users'     => 'integer',
        'max_books'     => 'integer',
        'trial_ends_at' => 'datetime',
        'suspended_at'  => 'datetime',
    ];

    protected $hidden = [
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            if (empty($tenant->ulid)) {
                $tenant->ulid = (string) Str::ulid();
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class, 'tenant_id');
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(TenantDomain::class, 'tenant_id')
            ->where('is_primary', true);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'tenant_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(
            \App\Domain\Billing\Models\Subscription::class,
            'tenant_id'
        )->latest();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            \App\Domain\Billing\Models\Plan::class,
            'plan_id'
        );
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::Active);
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::Suspended);
    }

    public function scopeOnTrial(Builder $query): Builder
    {
        return $query->where('trial_ends_at', '>', now());
    }

    // ─── Accessors & Mutators ───────────────────────────────────────────────────

    protected function storageUsedMb(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->storage_used / 1024 / 1024, 2)
        );
    }

    protected function storageQuotaMb(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->storage_quota / 1024 / 1024, 2)
        );
    }

    protected function storageUsagePercent(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->storage_quota > 0
                ? round(($this->storage_used / $this->storage_quota) * 100, 2)
                : 0.0
        );
    }

    protected function primaryUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => sprintf(
                'https://%s.%s',
                $this->slug,
                config('app.base_domain', 'kutubxona.uz')
            )
        );
    }

    // ─── Business Logic ─────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function hasStorageCapacity(int $bytesRequired): bool
    {
        return ($this->storage_used + $bytesRequired) <= $this->storage_quota;
    }

    public function incrementStorageUsed(int $bytes): bool
    {
        return $this->increment('storage_used', $bytes) > 0;
    }

    public function decrementStorageUsed(int $bytes): bool
    {
        return $this->decrement('storage_used', max(0, $bytes)) > 0;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function hasSetting(string $key): bool
    {
        return data_get($this->settings, $key) !== null;
    }

    public function isFeatureEnabled(string $feature): bool
    {
        return (bool) $this->getSetting("features.{$feature}", false);
    }

    public function getDatabaseConfig(): ?array
    {
        return $this->getSetting('dedicated_db');
    }

    public function hasDedicatedConnection(): bool
    {
        return $this->getDatabaseConfig() !== null;
    }

    public function getS3Prefix(): string
    {
        return "tenants/{$this->id}";
    }
}
