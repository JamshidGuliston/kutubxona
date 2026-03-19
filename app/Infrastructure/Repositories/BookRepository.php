<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Book\Models\Book;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class BookRepository
{
    private const DEFAULT_WITH = ['author', 'publisher', 'categories', 'tags'];
    private const DETAIL_WITH  = ['author', 'publisher', 'categories', 'tags', 'files', 'primaryCategory'];

    public function __construct(
        private readonly Book $model,
    ) {}

    public function create(array $data): Book
    {
        return $this->model->create($data);
    }

    public function update(Book $book, array $data): Book
    {
        $book->update($data);
        return $book->fresh();
    }

    public function delete(Book $book): bool
    {
        return (bool) $book->delete();
    }

    public function findById(int $id): ?Book
    {
        return $this->model->find($id);
    }

    public function findByIdWithRelations(int $id): ?Book
    {
        return $this->model
            ->with(self::DETAIL_WITH)
            ->find($id);
    }

    public function findBySlug(string $slug): ?Book
    {
        return $this->model
            ->with(self::DEFAULT_WITH)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Paginated book list with filters.
     *
     * @param array{
     *   search?: string,
     *   author_id?: int,
     *   publisher_id?: int,
     *   category_id?: int,
     *   tag?: string,
     *   language?: string,
     *   year_from?: int,
     *   year_to?: int,
     *   status?: string,
     *   is_featured?: bool,
     *   is_free?: bool,
     *   sort?: string,
     *   order?: string
     * } $filters
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model
            ->with(self::DEFAULT_WITH)
            ->where('status', $filters['status'] ?? 'published');

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Full-text search with filters.
     */
    public function search(string $query, array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $builder = $this->model
            ->with(self::DEFAULT_WITH)
            ->published()
            ->whereFullText(['title', 'description'], $query, ['mode' => 'boolean'])
            ->orderByRaw("MATCH(title, description) AGAINST(? IN BOOLEAN MODE) DESC", [$query]);

        $this->applyFilters($builder, $filters);

        return $builder->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get popular books (by download count).
     */
    public function getPopular(int $limit = 20): Collection
    {
        return $this->model
            ->with(self::DEFAULT_WITH)
            ->published()
            ->popular()
            ->limit($limit)
            ->get();
    }

    /**
     * Get featured books.
     */
    public function getFeatured(int $limit = 10): Collection
    {
        return $this->model
            ->with(self::DEFAULT_WITH)
            ->published()
            ->featured()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get new arrivals (last 30 days).
     */
    public function getNewArrivals(int $limit = 20): Collection
    {
        return $this->model
            ->with(self::DEFAULT_WITH)
            ->published()
            ->newArrivals()
            ->limit($limit)
            ->get();
    }

    /**
     * Count books in tenant.
     */
    public function count(array $filters = []): int
    {
        $query = $this->model->newQuery();
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        return $query->count();
    }

    /**
     * Get books for a specific author.
     */
    public function getByAuthor(int $authorId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(self::DEFAULT_WITH)
            ->published()
            ->where('author_id', $authorId)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get books for a specific category (including subcategories).
     */
    public function getByCategory(int $categoryId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->with(self::DEFAULT_WITH)
            ->published()
            ->where(function (Builder $q) use ($categoryId): void {
                $q->where('category_id', $categoryId)
                  ->orWhereHas('categories', fn ($q) => $q->where('categories.id', $categoryId));
            })
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['author_id'])) {
            $query->where('author_id', (int) $filters['author_id']);
        }

        if (!empty($filters['publisher_id'])) {
            $query->where('publisher_id', (int) $filters['publisher_id']);
        }

        if (!empty($filters['category_id'])) {
            $query->where(function (Builder $q) use ($filters): void {
                $q->where('category_id', (int) $filters['category_id'])
                  ->orWhereHas('categories', fn ($q) => $q->where('categories.id', $filters['category_id']));
            });
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

        if (isset($filters['is_featured'])) {
            $query->where('is_featured', (bool) $filters['is_featured']);
        }

        if (isset($filters['is_free'])) {
            $query->where('is_free', (bool) $filters['is_free']);
        }

        if (!empty($filters['tag'])) {
            $query->whereHas('tags', fn ($q) => $q->where('slug', $filters['tag']));
        }
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $allowedSorts = ['title', 'created_at', 'download_count', 'average_rating', 'published_year'];
        $sort  = in_array($filters['sort'] ?? '', $allowedSorts) ? $filters['sort'] : 'created_at';
        $order = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $order);
    }
}
