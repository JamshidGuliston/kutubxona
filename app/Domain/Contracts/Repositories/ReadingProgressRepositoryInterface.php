<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Repositories;

use App\Domain\Reading\Models\ReadingProgress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ReadingProgressRepositoryInterface
{
    /**
     * Find reading progress for a specific user/book combination.
     */
    public function findByUserAndBook(int $userId, int $bookId, int $tenantId): ?ReadingProgress;

    /**
     * Find reading progress for a specific user/audiobook combination.
     */
    public function findByUserAndAudioBook(int $userId, int $audiobookId, int $tenantId): ?ReadingProgress;

    /**
     * Find by primary key.
     */
    public function findById(int $id): ?ReadingProgress;

    /**
     * Create or update (upsert) reading progress for a user+book pair.
     */
    public function createOrUpdate(int $userId, int $bookId, int $tenantId, array $data): ReadingProgress;

    /**
     * Return the reading history (all entries) for a user, ordered by last_read_at desc.
     */
    public function getForUser(int $userId, int $tenantId, int $perPage): LengthAwarePaginator;

    /**
     * Return only in-progress books for a user.
     */
    public function getInProgressForUser(int $userId, int $tenantId): Collection;

    /**
     * Return completed books for a user.
     */
    public function getCompletedForUser(int $userId, int $tenantId): Collection;

    /**
     * Mark a progress record as complete.
     */
    public function markComplete(int $id): bool;

    /**
     * Delete a progress record.
     */
    public function delete(int $id): bool;

    /**
     * Aggregate reading statistics for a tenant (used by analytics).
     *
     * @return array{
     *   total_reading_sessions: int,
     *   total_books_started: int,
     *   total_books_completed: int,
     *   avg_completion_rate: float,
     *   total_reading_time_seconds: int,
     * }
     */
    public function getTenantStats(int $tenantId): array;

    /**
     * Aggregate per-book stats for analytics.
     *
     * @return array{
     *   unique_readers: int,
     *   completions: int,
     *   avg_progress: float,
     *   total_reading_time_seconds: int,
     * }
     */
    public function getBookStats(int $bookId, int $tenantId): array;
}
