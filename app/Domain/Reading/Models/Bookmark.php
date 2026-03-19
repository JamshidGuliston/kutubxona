<?php

declare(strict_types=1);

namespace App\Domain\Reading\Models;

use App\Domain\Book\Models\Book;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $book_id
 * @property int|null $page
 * @property string|null $cfi
 * @property string|null $title
 * @property string|null $note
 * @property string $color
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class Bookmark extends Model
{
    protected $table = 'bookmarks';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'book_id',
        'page',
        'cfi',
        'title',
        'note',
        'color',
    ];

    protected $casts = [
        'page' => 'integer',
    ];

    protected $hidden = ['tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $bookmark): void {
            if (app()->has('tenant') && empty($bookmark->tenant_id)) {
                $bookmark->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($bookmark->user_id)) {
                $bookmark->user_id = auth()->id();
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForBook(Builder $query, int $bookId): Builder
    {
        return $query->where('book_id', $bookId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('page')->orderBy('created_at');
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public static function getAvailableColors(): array
    {
        return ['yellow', 'green', 'blue', 'pink', 'purple', 'red', 'orange'];
    }
}
