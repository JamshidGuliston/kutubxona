<?php

declare(strict_types=1);

namespace App\Domain\Library\Models;

use App\Domain\Book\Models\Book;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $book_id
 * @property int $rating        1–5
 * @property string|null $title
 * @property string|null $body
 * @property bool $is_approved
 * @property \Carbon\Carbon|null $approved_at
 * @property int|null $approved_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Review extends Model
{
    use HasFactory;
    use HasTenantScope;
    use SoftDeletes;

    protected $table = 'reviews';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'book_id',
        'rating',
        'title',
        'body',
        'is_approved',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'rating'      => 'integer',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
    ];

    protected $hidden = ['tenant_id'];

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('is_approved', true);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('is_approved', false);
    }

    public function scopeForBook(Builder $query, int $bookId): Builder
    {
        return $query->where('book_id', $bookId);
    }

    public function scopeByRating(Builder $query, int $rating): Builder
    {
        return $query->where('rating', $rating);
    }

    public function scopeHighRated(Builder $query, int $minRating = 4): Builder
    {
        return $query->where('rating', '>=', $minRating);
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function approve(int $approvedById): bool
    {
        $updated = $this->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => $approvedById,
        ]);

        if ($updated) {
            // Trigger book rating recalculation
            $this->book?->recalculateRating();
        }

        return $updated;
    }

    public function reject(): bool
    {
        return $this->update([
            'is_approved' => false,
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    public function isPositive(): bool
    {
        return $this->rating >= 4;
    }

    public function isNegative(): bool
    {
        return $this->rating <= 2;
    }
}
