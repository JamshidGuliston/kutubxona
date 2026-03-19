<?php

declare(strict_types=1);

namespace App\Domain\Reading\Models;

use App\Domain\AudioBook\Models\AudioBook;
use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookFile;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int|null $book_id
 * @property int|null $audiobook_id
 * @property int|null $book_file_id
 * @property int|null $current_page
 * @property string|null $current_cfi
 * @property int|null $current_chapter
 * @property int|null $current_position
 * @property int|null $total_pages
 * @property float $percentage
 * @property bool $is_completed
 * @property \Carbon\Carbon|null $completed_at
 * @property int $reading_time
 * @property \Carbon\Carbon|null $last_read_at
 * @property array|null $device_info
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class ReadingProgress extends Model
{
    protected $table = 'reading_progress';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'book_id',
        'audiobook_id',
        'book_file_id',
        'current_page',
        'current_cfi',
        'current_chapter',
        'current_position',
        'total_pages',
        'percentage',
        'is_completed',
        'completed_at',
        'reading_time',
        'last_read_at',
        'device_info',
    ];

    protected $casts = [
        'current_page'    => 'integer',
        'current_chapter' => 'integer',
        'current_position'=> 'integer',
        'total_pages'     => 'integer',
        'percentage'      => 'decimal:2',
        'is_completed'    => 'boolean',
        'reading_time'    => 'integer',
        'device_info'     => 'array',
        'completed_at'    => 'datetime',
        'last_read_at'    => 'datetime',
    ];

    protected $hidden = ['tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $progress): void {
            if (app()->has('tenant') && empty($progress->tenant_id)) {
                $progress->tenant_id = app('tenant')->id;
            }
        });

        static::saving(function (self $progress): void {
            // Auto-set completion
            if ($progress->percentage >= 100 && !$progress->is_completed) {
                $progress->is_completed = true;
                $progress->completed_at = now();
            }
            $progress->last_read_at = now();
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

    public function audioBook(): BelongsTo
    {
        return $this->belongsTo(AudioBook::class, 'audiobook_id');
    }

    public function bookFile(): BelongsTo
    {
        return $this->belongsTo(BookFile::class, 'book_file_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('is_completed', true);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('is_completed', false)
            ->where('percentage', '>', 0);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecentlyRead(Builder $query): Builder
    {
        return $query->whereNotNull('last_read_at')
            ->orderByDesc('last_read_at');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    protected function readingTimeFormatted(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $seconds = $this->reading_time;
                $hours   = intdiv($seconds, 3600);
                $minutes = intdiv($seconds % 3600, 60);
                if ($hours > 0) return "{$hours}h {$minutes}m";
                return "{$minutes}m";
            }
        );
    }

    protected function progressPercent(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) round($this->percentage)
        );
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->is_completed;
    }

    public function isInProgress(): bool
    {
        return !$this->is_completed && $this->percentage > 0;
    }

    public function addReadingTime(int $seconds): void
    {
        $this->increment('reading_time', $seconds);
    }

    public function updateProgress(array $data): bool
    {
        return $this->update(array_filter([
            'current_page'     => $data['current_page'] ?? null,
            'current_cfi'      => $data['current_cfi'] ?? null,
            'current_chapter'  => $data['current_chapter'] ?? null,
            'current_position' => $data['current_position'] ?? null,
            'percentage'       => $data['percentage'] ?? null,
            'reading_time'     => isset($data['reading_time'])
                ? $this->reading_time + $data['reading_time']
                : null,
        ], fn ($v) => $v !== null));
    }
}
