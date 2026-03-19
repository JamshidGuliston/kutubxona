<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Reading\Models\ReadingProgress;
use Illuminate\Database\Eloquent\Collection;

final class ReadingProgressRepository
{
    public function __construct(
        private readonly ReadingProgress $model,
    ) {}

    /**
     * Update existing progress or create new record.
     */
    public function updateOrCreate(array $attributes, array $values): ReadingProgress
    {
        return $this->model->updateOrCreate($attributes, $values);
    }

    /**
     * Find progress for a specific user and book.
     */
    public function findForUserAndBook(int $userId, int $bookId): ?ReadingProgress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->first();
    }

    /**
     * Find progress for a specific user and audiobook.
     */
    public function findForUserAndAudioBook(int $userId, int $audiobookId): ?ReadingProgress
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('audiobook_id', $audiobookId)
            ->first();
    }

    /**
     * Get all in-progress items for a user.
     */
    public function getInProgress(int $userId): Collection
    {
        return $this->model
            ->with(['book.author', 'audioBook.author'])
            ->where('user_id', $userId)
            ->where('is_completed', false)
            ->where('percentage', '>', 0)
            ->recentlyRead()
            ->get();
    }

    /**
     * Get all completed items for a user.
     */
    public function getCompleted(int $userId): Collection
    {
        return $this->model
            ->with(['book.author', 'audioBook.author'])
            ->where('user_id', $userId)
            ->where('is_completed', true)
            ->orderByDesc('completed_at')
            ->get();
    }

    /**
     * Get recently read items for a user (history).
     */
    public function getRecentlyRead(int $userId, int $limit = 20): Collection
    {
        return $this->model
            ->with(['book.author', 'audioBook.author'])
            ->where('user_id', $userId)
            ->whereNotNull('last_read_at')
            ->recentlyRead()
            ->limit($limit)
            ->get();
    }

    /**
     * Get total reading time for a user (seconds).
     */
    public function getTotalReadingTime(int $userId): int
    {
        return (int) $this->model
            ->where('user_id', $userId)
            ->sum('reading_time');
    }

    /**
     * Count completed books/audiobooks for a user.
     */
    public function getCompletedCount(int $userId): int
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('is_completed', true)
            ->count();
    }
}
