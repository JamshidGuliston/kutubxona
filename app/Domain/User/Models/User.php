<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use App\Domain\Reading\Models\Bookmark;
use App\Domain\Reading\Models\Favorite;
use App\Domain\Reading\Models\Highlight;
use App\Domain\Reading\Models\ReadingProgress;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property \Carbon\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $avatar
 * @property string $status
 * @property string $locale
 * @property array|null $preferences
 * @property \Carbon\Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property \Carbon\Carbon|null $password_changed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class User extends Authenticatable implements FilamentUser, JWTSubject, MustVerifyEmail
{
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    protected string $guard_name = 'api';

    protected $fillable = [
        'tenant_id',
        'ulid',
        'name',
        'email',
        'email_verified_at',
        'password',
        'avatar',
        'status',
        'locale',
        'preferences',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
        'email_verification_token',
        'password_reset_token',
        'password_reset_expires',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_token',
        'password_reset_token',
        'password_reset_expires',
        'two_factor_secret',
        'two_factor_recovery',
    ];

    protected $casts = [
        'email_verified_at'     => 'datetime',
        'last_login_at'         => 'datetime',
        'password_changed_at'   => 'datetime',
        'password_reset_expires'=> 'datetime',
        'preferences'           => 'array',
        'two_factor_recovery'   => 'encrypted:array',
        'two_factor_secret'     => 'encrypted',
    ];

    /**
     * Permission team field — maps to tenant_id for Spatie multi-team.
     */
    protected string $teamForeignKey = 'tenant_id';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $user): void {
            if (empty($user->ulid)) {
                $user->ulid = (string) Str::ulid();
            }
            if (app()->has('tenant') && empty($user->tenant_id)) {
                $user->tenant_id = app('tenant')->id;
            }
        });
    }

    // ─── FilamentUser ────────────────────────────────────────────────────────────

    public function canAccessPanel(Panel $panel): bool
    {
        $hasSuperAdmin = $this->roles()
            ->where('name', 'super_admin')
            ->where('guard_name', 'api')
            ->exists();

        return match ($panel->getId()) {
            'super-admin' => $hasSuperAdmin,
            'admin'       => $hasSuperAdmin || $this->roles()
                ->where('name', 'tenant_admin')
                ->where('guard_name', 'api')
                ->exists(),
            default       => false,
        };
    }

    // ─── JWTSubject ─────────────────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'tid'  => $this->tenant_id,
            'uid'  => $this->ulid,
            'name' => $this->name,
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class, 'user_id');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'user_id');
    }

    public function highlights(): HasMany
    {
        return $this->hasMany(Highlight::class, 'user_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class, 'user_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(\App\Domain\Reading\Models\Review::class, 'user_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if ($this->avatar) {
                    return str_starts_with($this->avatar, 'http')
                        ? $this->avatar
                        : config('filesystems.disks.s3.url') . '/' . $this->avatar;
                }
                // Generate Gravatar URL as fallback
                $hash = md5(strtolower(trim($this->email)));
                return "https://www.gravatar.com/avatar/{$hash}?d=identicon&s=200";
            }
        );
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBanned(): bool
    {
        return $this->status === 'banned';
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isTenantAdmin(): bool
    {
        return $this->hasRole('tenant_admin');
    }

    public function isTenantManager(): bool
    {
        return $this->hasRole('tenant_manager');
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function recordLogin(string $ip): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    public function getPreference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    /**
     * Generate email verification token.
     */
    public function generateEmailVerificationToken(): string
    {
        $token = Str::random(64);
        $this->update(['email_verification_token' => $token]);
        return $token;
    }

    /**
     * Generate password reset token.
     */
    public function generatePasswordResetToken(): string
    {
        $token = Str::random(64);
        $this->update([
            'password_reset_token'   => $token,
            'password_reset_expires' => now()->addHour(),
        ]);
        return $token;
    }

    public function isPasswordResetTokenValid(string $token): bool
    {
        return $this->password_reset_token === $token
            && $this->password_reset_expires?->isFuture();
    }
}
