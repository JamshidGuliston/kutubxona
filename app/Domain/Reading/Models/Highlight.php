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
 * @property string|null $cfi_start
 * @property string|null $cfi_end
 * @property string $selected_text
 * @property string|null $note
 * @property string $color
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class Highlight extends Model
{
    protected $table = 'highlights';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'book_id',
        'page',
        'cfi_start',
        'cfi_end',
        'selected_text',
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

        static::creating(function (self $highlight): void {
            if (app()->has('tenant') && empty($highlight->tenant_id)) {
                $highlight->tenant_id = app('tenant')->id;
            }
            if (auth()->check() && empty($highlight->user_id)) {
                $highlight->user_id = auth()->id();
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

    public function scopeByColor(Builder $query, string $color): Builder
    {
        return $query->where('color', $color);
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

    public function hasNote(): bool
    {
        return !empty($this->note);
    }

    public static function getAvailableColors(): array
    {
        return ['yellow', 'green', 'blue', 'pink', 'purple'];
    }

    public function getSnippet(int $maxLength = 100): string
    {
        return strlen($this->selected_text) > $maxLength
            ? substr($this->selected_text, 0, $maxLength) . '...'
            : $this->selected_text;
    }
}
