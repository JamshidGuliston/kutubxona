<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Book\CreateBookDTO;
use App\Application\DTOs\Book\UpdateBookDTO;
use App\Domain\Book\Models\Book;
use App\Domain\Book\Models\BookFile;
use App\Events\BookUploaded;
use App\Infrastructure\Repositories\BookRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BookService
{
    public function __construct(
        private readonly BookRepository $bookRepository,
        private readonly StorageService $storageService,
    ) {}

    /**
     * Create a new book and dispatch file processing job if file provided.
     */
    public function createBook(CreateBookDTO $dto): Book
    {
        return DB::transaction(function () use ($dto): Book {
            $tenant = app('tenant');

            // Generate unique slug
            $slug = $this->generateUniqueSlug($dto->title);

            // Create book record
            $bookData = array_merge($dto->toArray(), ['slug' => $slug]);
            $book = $this->bookRepository->create($bookData);

            // Sync tags and categories
            if (!empty($dto->tagIds)) {
                $book->tags()->sync($dto->tagIds);
            }
            if (!empty($dto->categoryIds)) {
                $book->categories()->sync($dto->categoryIds);
            }

            // Handle file upload
            if ($dto->bookFile !== null) {
                $tempKey = $this->storageService->uploadTemp(
                    $dto->bookFile,
                    $tenant->getS3Prefix() . '/temp'
                );

                $bookFile = BookFile::create([
                    'tenant_id'         => $tenant->id,
                    'book_id'           => $book->id,
                    'file_type'         => strtolower($dto->bookFile->getClientOriginalExtension()),
                    's3_key'            => $tempKey,
                    's3_bucket'         => config('filesystems.disks.s3.bucket'),
                    'original_name'     => $dto->bookFile->getClientOriginalName(),
                    'file_size'         => $dto->bookFile->getSize(),
                    'mime_type'         => $dto->bookFile->getMimeType() ?? 'application/octet-stream',
                    'is_primary'        => true,
                    'processing_status' => 'pending',
                    'uploaded_by'       => auth()->id(),
                ]);

                event(new BookUploaded($book, $bookFile));
            }

            // Handle cover image
            if ($dto->coverImage !== null) {
                $coverKey = $this->storageService->uploadImage(
                    $dto->coverImage,
                    $tenant->getS3Prefix() . '/books/' . $book->id
                );
                $book->update(['cover_image' => $coverKey]);
            }

            // Clear tenant book cache
            $this->clearBookCache($tenant->id);

            return $book->load(['author', 'publisher', 'categories', 'tags', 'files']);
        });
    }

    /**
     * Update existing book details.
     */
    public function updateBook(Book $book, UpdateBookDTO $dto): Book
    {
        return DB::transaction(function () use ($book, $dto): Book {
            $tenant = app('tenant');

            $updateData = $dto->toArray();
            if (!empty($updateData)) {
                $this->bookRepository->update($book, $updateData);
            }

            // Sync tags if provided
            if ($dto->tagIds !== null) {
                $book->tags()->sync($dto->tagIds);
            }

            // Sync categories if provided
            if ($dto->categoryIds !== null) {
                $book->categories()->sync($dto->categoryIds);
            }

            // Handle new file upload
            if ($dto->bookFile !== null) {
                $tempKey = $this->storageService->uploadTemp(
                    $dto->bookFile,
                    $tenant->getS3Prefix() . '/temp'
                );

                $bookFile = BookFile::create([
                    'tenant_id'         => $tenant->id,
                    'book_id'           => $book->id,
                    'file_type'         => strtolower($dto->bookFile->getClientOriginalExtension()),
                    's3_key'            => $tempKey,
                    's3_bucket'         => config('filesystems.disks.s3.bucket'),
                    'original_name'     => $dto->bookFile->getClientOriginalName(),
                    'file_size'         => $dto->bookFile->getSize(),
                    'mime_type'         => $dto->bookFile->getMimeType() ?? 'application/octet-stream',
                    'is_primary'        => true,
                    'processing_status' => 'pending',
                    'uploaded_by'       => auth()->id(),
                ]);

                event(new BookUploaded($book, $bookFile));
            }

            // Handle new cover image
            if ($dto->coverImage !== null) {
                $coverKey = $this->storageService->uploadImage(
                    $dto->coverImage,
                    $tenant->getS3Prefix() . '/books/' . $book->id
                );
                $book->update(['cover_image' => $coverKey]);
            }

            $this->clearBookCache($tenant->id, $book->id);

            return $book->fresh(['author', 'publisher', 'categories', 'tags', 'files']);
        });
    }

    /**
     * Soft-delete a book.
     */
    public function deleteBook(Book $book): bool
    {
        $deleted = $this->bookRepository->delete($book);

        if ($deleted) {
            $this->clearBookCache(app('tenant')->id, $book->id);
        }

        return $deleted;
    }

    /**
     * Retrieve paginated book list with filters.
     */
    public function getBooks(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $tenant = app('tenant');
        $cacheKey = "tenant:{$tenant->id}:books:list:" . md5(serialize($filters)) . ":page:{$page}";

        return Cache::remember($cacheKey, 300, function () use ($filters, $page, $perPage) {
            return $this->bookRepository->paginate($filters, $page, $perPage);
        });
    }

    /**
     * Full-text search books.
     */
    public function searchBooks(string $query, array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $tenant = app('tenant');
        $cacheKey = "tenant:{$tenant->id}:search:" . md5($query . serialize($filters)) . ":page:{$page}";

        return Cache::remember($cacheKey, 120, function () use ($query, $filters, $page, $perPage) {
            return $this->bookRepository->search($query, $filters, $page, $perPage);
        });
    }

    /**
     * Get popular books (most downloaded in past 30 days).
     */
    public function getPopularBooks(int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        $tenant = app('tenant');
        $cacheKey = "tenant:{$tenant->id}:books:popular:{$limit}";

        return Cache::remember($cacheKey, 3600, function () use ($limit) {
            return $this->bookRepository->getPopular($limit);
        });
    }

    /**
     * Get featured books.
     */
    public function getFeaturedBooks(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        $tenant = app('tenant');
        $cacheKey = "tenant:{$tenant->id}:books:featured:{$limit}";

        return Cache::remember($cacheKey, 3600, function () use ($limit) {
            return $this->bookRepository->getFeatured($limit);
        });
    }

    /**
     * Get new arrivals (last 30 days).
     */
    public function getNewArrivals(int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return $this->bookRepository->getNewArrivals($limit);
    }

    /**
     * Get book with full relations (for detail page).
     */
    public function getBook(int $id): ?Book
    {
        $tenant = app('tenant');
        $cacheKey = "tenant:{$tenant->id}:book:{$id}";

        $book = Cache::remember($cacheKey, 900, function () use ($id) {
            return $this->bookRepository->findByIdWithRelations($id);
        });

        if ($book) {
            // Increment view count asynchronously
            \App\Jobs\IncrementBookView::dispatchAfterResponse($id);
        }

        return $book;
    }

    /**
     * Generate signed download URL for a book file.
     */
    public function getDownloadUrl(Book $book, string $fileType = 'pdf'): array
    {
        $file = $book->files()
            ->where('file_type', $fileType)
            ->where('processing_status', 'ready')
            ->first();

        if (!$file) {
            throw new \RuntimeException("No ready file of type '{$fileType}' found for this book.");
        }

        $url = $file->getSignedUrl(3600);

        // Increment download count
        $book->incrementDownloadCount();

        return [
            'download_url' => $url,
            'expires_at'   => now()->addHour()->toIso8601String(),
            'file_type'    => $file->file_type,
            'file_size'    => $file->file_size,
        ];
    }

    /**
     * Generate signed streaming URL for reading.
     */
    public function getStreamingUrl(Book $book, string $fileType = 'pdf'): string
    {
        $file = $book->files()
            ->where('file_type', $fileType)
            ->where('processing_status', 'ready')
            ->first();

        if (!$file) {
            throw new \RuntimeException("No ready file of type '{$fileType}' found for this book.");
        }

        return $file->getStreamingUrl(15);
    }

    /**
     * Publish a book (change status to published).
     */
    public function publishBook(Book $book): Book
    {
        $book->update([
            'status'       => 'published',
            'published_at' => $book->published_at ?? now(),
        ]);

        $this->clearBookCache(app('tenant')->id, $book->id);

        return $book->fresh();
    }

    /**
     * Archive a book.
     */
    public function archiveBook(Book $book): Book
    {
        $book->update(['status' => 'archived']);
        $this->clearBookCache(app('tenant')->id, $book->id);
        return $book->fresh();
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function generateUniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title);
        $slug     = $baseSlug;
        $counter  = 1;

        while (Book::withoutGlobalScopes()
            ->where('tenant_id', app('tenant')->id)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function clearBookCache(int $tenantId, ?int $bookId = null): void
    {
        // Clear specific book cache
        if ($bookId) {
            Cache::forget("tenant:{$tenantId}:book:{$bookId}");
        }

        // Clear list caches (pattern deletion via Redis tags or manual)
        Cache::forget("tenant:{$tenantId}:books:popular:20");
        Cache::forget("tenant:{$tenantId}:books:featured:10");
    }
}
