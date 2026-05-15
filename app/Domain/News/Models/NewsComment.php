<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\News\Observers\NewsCommentObserver;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $news_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $body
 * @property bool $is_approved
 */
#[ObservedBy([NewsCommentObserver::class])]
final class NewsComment extends Model
{
    protected $table = 'news_comments';

    protected $fillable = ['tenant_id', 'news_id', 'user_id', 'parent_id', 'body', 'is_approved'];

    protected $casts = ['is_approved' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $c): void {
            if (app()->has('tenant') && empty($c->tenant_id)) {
                $c->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($c->user_id)) {
                $c->user_id = auth()->id();
            }
        });
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function news(): BelongsTo { return $this->belongsTo(News::class, 'news_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function replies(): HasMany { return $this->hasMany(self::class, 'parent_id'); }

    public function scopeApproved(Builder $q): Builder { return $q->where('is_approved', true); }
    public function scopePending(Builder $q): Builder { return $q->where('is_approved', false); }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }
}
