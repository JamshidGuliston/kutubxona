<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Book\Models\Book;
use App\Domain\Reading\Models\Bookmark;
use App\Domain\Reading\Models\Favorite;
use App\Domain\Reading\Models\Highlight;
use App\Domain\Reading\Models\ReadingProgress;
use App\Domain\User\Models\User;
use App\Infrastructure\Repositories\ReadingProgressRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ReadingService
{
    public function __construct(
        private readonly ReadingProgressRepository $progressRepository,
    ) {}

    // ─── Reading Progress ─────────────────────────────────────────────────────────

    /**
     * Update or create reading progress for a book.
     */
    public function updateProgress(User $user, int $bookId, array $data): ReadingProgress
    {
        return $this->progressRepository->updateOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'user_id'   => $user->id,
                'book_id'   => $bookId,
            ],
            [
                'current_page'  => $data['current_page'] ?? null,
                'current_cfi'   => $data['current_cfi'] ?? null,
                'percentage'    => $data['percentage'] ?? 0,
                'reading_time'  => DB::raw('reading_time + ' . (int) ($data['reading_time'] ?? 0)),
            ]
        );
    }

    /**
     * Update audiobook listening progress.
     */
    public function updateAudioProgress(User $user, int $audiobookId, array $data): ReadingProgress
    {
        return $this->progressRepository->updateOrCreate(
            [
                'tenant_id'   => $user->tenant_id,
                'user_id'     => $user->id,
                'audiobook_id'=> $audiobookId,
            ],
            [
                'current_chapter'  => $data['current_chapter'] ?? null,
                'current_position' => $data['current_position'] ?? null,
                'percentage'       => $data['percentage'] ?? 0,
            ]
        );
    }

    /**
     * Get all reading progress for a user.
     */
    public function getUserProgress(User $user): Collection
    {
        return ReadingProgress::with(['book.author', 'book.cover_thumbnail', 'audioBook'])
            ->where('user_id', $user->id)
            ->recentlyRead()
            ->get();
    }

    /**
     * Get reading history (recently read books).
     */
    public function getReadingHistory(User $user, int $limit = 20): Collection
    {
        return ReadingProgress::with(['book.author', 'audioBook'])
            ->where('user_id', $user->id)
            ->whereNotNull('last_read_at')
            ->recentlyRead()
            ->limit($limit)
            ->get();
    }

    /**
     * Get progress for a specific book.
     */
    public function getProgressForBook(User $user, int $bookId): ?ReadingProgress
    {
        return ReadingProgress::where('user_id', $user->id)
            ->where('book_id', $bookId)
            ->first();
    }

    // ─── Bookmarks ───────────────────────────────────────────────────────────────

    /**
     * Create a new bookmark.
     */
    public function createBookmark(User $user, int $bookId, array $data): Bookmark
    {
        return Bookmark::create([
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'book_id'   => $bookId,
            'page'      => $data['page'] ?? null,
            'cfi'       => $data['cfi'] ?? null,
            'title'     => $data['title'] ?? null,
            'note'      => $data['note'] ?? null,
            'color'     => $data['color'] ?? 'yellow',
        ]);
    }

    /**
     * Update a bookmark.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function updateBookmark(User $user, Bookmark $bookmark, array $data): Bookmark
    {
        if (!$bookmark->belongsToUser($user->id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot update another user\'s bookmark.');
        }

        $bookmark->update(array_filter([
            'title' => $data['title'] ?? null,
            'note'  => $data['note'] ?? null,
            'color' => $data['color'] ?? null,
        ], fn ($v) => $v !== null));

        return $bookmark->fresh();
    }

    /**
     * Delete a bookmark.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function deleteBookmark(User $user, Bookmark $bookmark): bool
    {
        if (!$bookmark->belongsToUser($user->id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot delete another user\'s bookmark.');
        }

        return (bool) $bookmark->delete();
    }

    /**
     * Get all bookmarks for a user's book.
     */
    public function getBookmarks(User $user, int $bookId): Collection
    {
        return Bookmark::forUser($user->id)
            ->forBook($bookId)
            ->ordered()
            ->get();
    }

    // ─── Highlights ──────────────────────────────────────────────────────────────

    /**
     * Create a highlight.
     */
    public function createHighlight(User $user, int $bookId, array $data): Highlight
    {
        return Highlight::create([
            'tenant_id'     => $user->tenant_id,
            'user_id'       => $user->id,
            'book_id'       => $bookId,
            'page'          => $data['page'] ?? null,
            'cfi_start'     => $data['cfi_start'] ?? null,
            'cfi_end'       => $data['cfi_end'] ?? null,
            'selected_text' => $data['selected_text'],
            'note'          => $data['note'] ?? null,
            'color'         => $data['color'] ?? 'yellow',
        ]);
    }

    /**
     * Update a highlight.
     */
    public function updateHighlight(User $user, Highlight $highlight, array $data): Highlight
    {
        if (!$highlight->belongsToUser($user->id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot update another user\'s highlight.');
        }

        $highlight->update(array_filter([
            'note'  => $data['note'] ?? null,
            'color' => $data['color'] ?? null,
        ], fn ($v) => $v !== null));

        return $highlight->fresh();
    }

    /**
     * Delete a highlight.
     */
    public function deleteHighlight(User $user, Highlight $highlight): bool
    {
        if (!$highlight->belongsToUser($user->id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot delete another user\'s highlight.');
        }

        return (bool) $highlight->delete();
    }

    /**
     * Get all highlights for a user's book.
     */
    public function getHighlights(User $user, int $bookId, ?string $color = null): Collection
    {
        $query = Highlight::forUser($user->id)->forBook($bookId)->ordered();

        if ($color !== null) {
            $query->byColor($color);
        }

        return $query->get();
    }

    // ─── Favorites ───────────────────────────────────────────────────────────────

    /**
     * Toggle favorite status for a book.
     */
    public function toggleBookFavorite(User $user, int $bookId): bool
    {
        $existing = Favorite::where('user_id', $user->id)
            ->where('book_id', $bookId)
            ->first();

        if ($existing) {
            $existing->delete();
            return false; // Removed from favorites
        }

        Favorite::create([
            'tenant_id' => $user->tenant_id,
            'user_id'   => $user->id,
            'book_id'   => $bookId,
        ]);

        return true; // Added to favorites
    }

    /**
     * Toggle favorite status for an audiobook.
     */
    public function toggleAudioFavorite(User $user, int $audiobookId): bool
    {
        $existing = Favorite::where('user_id', $user->id)
            ->where('audiobook_id', $audiobookId)
            ->first();

        if ($existing) {
            $existing->delete();
            return false;
        }

        Favorite::create([
            'tenant_id'   => $user->tenant_id,
            'user_id'     => $user->id,
            'audiobook_id'=> $audiobookId,
        ]);

        return true;
    }

    /**
     * Get user's favorites.
     */
    public function getFavorites(User $user): Collection
    {
        return Favorite::with(['book.author', 'audioBook.author'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();
    }

    /**
     * Check if a book is in user's favorites.
     */
    public function isFavorited(User $user, int $bookId): bool
    {
        return Favorite::where('user_id', $user->id)
            ->where('book_id', $bookId)
            ->exists();
    }
}
