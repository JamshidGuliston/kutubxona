<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\AudioBook\Models\AudioBook;
use App\Domain\Book\Models\Author;
use App\Domain\Book\Models\Book;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SearchService
{
    /**
     * Full search across books, audiobooks, and authors.
     */
    public function search(
        string $query,
        array $filters = [],
        string $type = 'all',
        int $page = 1,
        int $perPage = 20
    ): array {
        $cleanQuery = $this->sanitizeQuery($query);
        $tenant     = app('tenant');

        $results = [];

        if ($type === 'all' || $type === 'books') {
            $results['books'] = $this->searchBooks($cleanQuery, $filters, $page, $perPage);
        }

        if ($type === 'all' || $type === 'audiobooks') {
            $results['audiobooks'] = $this->searchAudioBooks($cleanQuery, $filters, $page, $perPage);
        }

        if ($type === 'all' || $type === 'authors') {
            $results['authors'] = $this->searchAuthors($cleanQuery, $page, $perPage);
        }

        // Log search query for analytics
        $this->logSearchQuery($cleanQuery, $tenant->id);

        return $results;
    }

    /**
     * Autocomplete: fast search for search bar dropdown.
     */
    public function autocomplete(string $query, int $limit = 8): Collection
    {
        $cleanQuery = $this->sanitizeQuery($query);
        if (strlen($cleanQuery) < 2) {
            return collect([]);
        }

        $tenant   = app('tenant');
        $cacheKey = "tenant:{$tenant->id}:autocomplete:" . md5($cleanQuery);

        return Cache::remember($cacheKey, 120, function () use ($cleanQuery, $limit, $tenant): Collection {
            $results = collect();

            // Books
            $books = Book::select(['id', 'title', 'cover_thumbnail', 'slug'])
                ->published()
                ->where(function ($q) use ($cleanQuery) {
                    $q->where('title', 'LIKE', "%{$cleanQuery}%")
                      ->orWhereFullText(['title', 'description'], $cleanQuery);
                })
                ->limit((int) ceil($limit * 0.6))
                ->get()
                ->map(fn (Book $b) => [
                    'type'  => 'book',
                    'id'    => $b->id,
                    'title' => $b->title,
                    'slug'  => $b->slug,
                    'cover' => $b->cover_thumbnail,
                ]);

            // Authors
            $authors = Author::select(['id', 'name', 'photo', 'slug'])
                ->where('name', 'LIKE', "%{$cleanQuery}%")
                ->limit((int) ceil($limit * 0.25))
                ->get()
                ->map(fn (Author $a) => [
                    'type' => 'author',
                    'id'   => $a->id,
                    'name' => $a->name,
                    'slug' => $a->slug,
                    'photo'=> $a->photo,
                ]);

            // Audiobooks
            $audiobooks = AudioBook::select(['id', 'title', 'cover_thumbnail', 'slug'])
                ->published()
                ->where('title', 'LIKE', "%{$cleanQuery}%")
                ->limit((int) ceil($limit * 0.15))
                ->get()
                ->map(fn (AudioBook $ab) => [
                    'type'  => 'audiobook',
                    'id'    => $ab->id,
                    'title' => $ab->title,
                    'slug'  => $ab->slug,
                    'cover' => $ab->cover_thumbnail,
                ]);

            return $results->concat($books)->concat($authors)->concat($audiobooks)->take($limit);
        });
    }

    /**
     * Get top search queries for analytics.
     */
    public function getTopSearchQueries(int $tenantId, int $limit = 20): array
    {
        $cacheKey = "tenant:{$tenantId}:search:top_queries";

        return Cache::remember($cacheKey, 3600, function () use ($tenantId, $limit): array {
            return DB::table('analytics_events')
                ->select('properties->query as query', DB::raw('COUNT(*) as count'))
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'search')
                ->whereNotNull(DB::raw('properties->>"$.query"'))
                ->groupBy(DB::raw('properties->>"$.query"'))
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
                ->toArray();
        });
    }

    // ─── Private methods ─────────────────────────────────────────────────────────

    private function searchBooks(
        string $query,
        array $filters,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        $q = Book::with(['author', 'categories'])
            ->published();

        // Full-text search
        if (!empty($query)) {
            $q->whereFullText(['title', 'description'], $query, ['mode' => 'boolean'])
              ->orderByRaw("MATCH(title, description) AGAINST(? IN BOOLEAN MODE) DESC", [$query]);
        }

        // Apply filters
        $this->applyBookFilters($q, $filters);

        return $q->paginate($perPage, ['*'], 'page', $page);
    }

    private function searchAudioBooks(
        string $query,
        array $filters,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        $q = AudioBook::with(['author'])
            ->published();

        if (!empty($query)) {
            $q->whereFullText(['title', 'description'], $query, ['mode' => 'boolean'])
              ->orderByRaw("MATCH(title, description) AGAINST(? IN BOOLEAN MODE) DESC", [$query]);
        }

        if (!empty($filters['language'])) {
            $q->where('language', $filters['language']);
        }

        return $q->paginate($perPage, ['*'], 'page', $page);
    }

    private function searchAuthors(string $query, int $page, int $perPage): LengthAwarePaginator
    {
        return Author::where('name', 'LIKE', "%{$query}%")
            ->orWhereFullText(['name'], $query)
            ->withCount('books')
            ->orderByDesc('books_count')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    private function applyBookFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): void
    {
        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }
        if (!empty($filters['publisher_id'])) {
            $query->where('publisher_id', $filters['publisher_id']);
        }
        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (!empty($filters['language'])) {
            $query->where('language', $filters['language']);
        }
        if (!empty($filters['year_from'])) {
            $query->where('published_year', '>=', (int) $filters['year_from']);
        }
        if (!empty($filters['year_to'])) {
            $query->where('published_year', '<=', (int) $filters['year_to']);
        }
        if (isset($filters['is_free'])) {
            $query->where('is_free', (bool) $filters['is_free']);
        }
    }

    private function sanitizeQuery(string $query): string
    {
        // Remove special MySQL full-text operators that could cause syntax errors
        $clean = preg_replace('/[+\-><\(\)~*"@]+/', ' ', $query);
        return trim((string) $clean);
    }

    private function logSearchQuery(string $query, int $tenantId): void
    {
        // Async: dispatch analytics job
        \App\Jobs\LogAnalyticsEvent::dispatchAfterResponse(
            $tenantId,
            auth()->id(),
            'search',
            null,
            null,
            ['query' => $query]
        );
    }
}
