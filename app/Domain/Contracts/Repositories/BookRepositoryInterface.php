<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Repositories;

use App\Domain\Book\Models\Book;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface BookRepositoryInterface
{
    /**
     * Find a single book by its primary key scoped to a tenant.
     */
    public function findById(int $id, int $tenantId): ?Book;

    /**
     * Return a paginated list of books for a tenant with optional filters.
     *
     * @param array{
     *   status?: string,
     *   author_id?: int,
     *   publisher_id?: int,
     *   category_id?: int,
     *   language?: string,
     *   year_from?: int,
     *   year_to?: int,
     *   is_featured?: bool,
     *   is_free?: bool,
     *   tag?: string,
     *   sort?: string,
     *   order?: 'asc'|'desc',
     * } $filters
     */
    public function findAll(int $tenantId, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Persist a new book record.
     */
    public function create(array $data): Book;

    /**
     * Update an existing book by its primary key.
     */
    public function update(int $id, array $data): Book;

    /**
     * Soft-delete a book.
     */
    public function delete(int $id): bool;

    /**
     * Full-text search across books for a tenant.
     *
     * @param array{
     *   author_id?: int,
     *   category_id?: int,
     *   language?: string,
     *   year_from?: int,
     *   year_to?: int,
     *   per_page?: int,
     *   page?: int,
     * } $filters
     */
    public function search(int $tenantId, string $query, array $filters): LengthAwarePaginator;

    /**
     * Return the most-downloaded books for a tenant.
     */
    public function getPopular(int $tenantId, int $limit): Collection;

    /**
     * Return book recommendations for a specific user.
     * Based on reading history, favorites, and similar readers.
     */
    public function getRecommended(int $tenantId, int $userId, int $limit): Collection;

    /**
     * Return recently added books for a tenant.
     */
    public function getLatest(int $tenantId, int $limit): Collection;
}
