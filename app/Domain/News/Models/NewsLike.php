<?php

declare(strict_types=1);

namespace App\Domain\News\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NewsLike extends Model
{
    protected $table = 'news_likes';

    // Only created_at — no updated_at on likes.
    public $timestamps = false;
    protected $dates = ['created_at'];

    protected $fillable = ['tenant_id', 'news_id', 'user_id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $l): void {
            if (app()->has('tenant') && empty($l->tenant_id)) {
                $l->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($l->user_id)) {
                $l->user_id = auth()->id();
            }
            if (empty($l->created_at)) {
                $l->created_at = now();
            }
        });
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function news(): BelongsTo { return $this->belongsTo(News::class, 'news_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
