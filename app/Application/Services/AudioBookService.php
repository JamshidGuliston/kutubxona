<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\AudioBook\Models\AudioBook;
use App\Domain\AudioBook\Models\AudioBookChapter;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AudioBookService
{
    public function __construct(
        private readonly StorageService $storageService,
    ) {}

    /**
     * Create a new audiobook.
     */
    public function createAudioBook(array $data, ?\Illuminate\Http\UploadedFile $coverImage = null): AudioBook
    {
        return DB::transaction(function () use ($data, $coverImage): AudioBook {
            $tenant = app('tenant');

            $slug = $this->generateUniqueSlug($data['title']);

            $audiobook = AudioBook::create(array_merge($data, [
                'tenant_id' => $tenant->id,
                'slug'      => $slug,
                'status'    => 'draft',
                'created_by'=> auth()->id(),
            ]));

            if ($coverImage !== null) {
                $coverKey = $this->storageService->uploadImage(
                    $coverImage,
                    $tenant->getS3Prefix() . '/audio/' . $audiobook->id
                );
                $audiobook->update(['cover_image' => $coverKey]);
            }

            return $audiobook->fresh();
        });
    }

    /**
     * Update audiobook details.
     */
    public function updateAudioBook(AudioBook $audiobook, array $data): AudioBook
    {
        $audiobook->update(array_filter($data, fn ($v) => $v !== null));
        Cache::forget("tenant:{$audiobook->tenant_id}:audiobook:{$audiobook->id}");
        return $audiobook->fresh();
    }

    /**
     * Delete an audiobook (and all chapters from S3).
     */
    public function deleteAudioBook(AudioBook $audiobook): bool
    {
        // Delete chapter files from S3
        foreach ($audiobook->chapters as $chapter) {
            $this->storageService->delete($chapter->s3_key);
        }

        return (bool) $audiobook->delete();
    }

    /**
     * List paginated audiobooks.
     */
    public function getAudioBooks(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = AudioBook::with(['author', 'category'])
            ->where('status', 'published');

        if (!empty($filters['search'])) {
            $query->whereFullText(['title', 'description'], $filters['search']);
        }
        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }
        if (!empty($filters['language'])) {
            $query->where('language', $filters['language']);
        }
        if (!empty($filters['is_free'])) {
            $query->where('is_free', true);
        }

        $sort  = $filters['sort'] ?? 'created_at';
        $order = $filters['order'] ?? 'desc';
        $query->orderBy($sort, $order);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get single audiobook with all chapters.
     */
    public function getAudioBook(int $id): ?AudioBook
    {
        $tenant = app('tenant');
        $cacheKey = "tenant:{$tenant->id}:audiobook:{$id}";

        return Cache::remember($cacheKey, 900, function () use ($id) {
            return AudioBook::with([
                'author',
                'publisher',
                'category',
                'chapters' => fn ($q) => $q->where('processing_status', 'ready')
            ])->find($id);
        });
    }

    /**
     * Add a chapter to an audiobook and process it.
     */
    public function addChapter(
        AudioBook $audiobook,
        \Illuminate\Http\UploadedFile $audioFile,
        array $chapterData
    ): AudioBookChapter {
        $tenant = app('tenant');

        // Determine next chapter number
        $nextNumber = $audiobook->chapters()->max('chapter_number') + 1;

        // Upload to temp
        $tempKey = $this->storageService->uploadTemp(
            $audioFile,
            $tenant->getS3Prefix() . '/temp'
        );

        $chapter = AudioBookChapter::create([
            'tenant_id'         => $tenant->id,
            'audiobook_id'      => $audiobook->id,
            'title'             => $chapterData['title'] ?? "Bob {$nextNumber}",
            'chapter_number'    => $chapterData['chapter_number'] ?? $nextNumber,
            's3_key'            => $tempKey,
            's3_bucket'         => config('filesystems.disks.s3.bucket'),
            'mime_type'         => $audioFile->getMimeType() ?? 'audio/mpeg',
            'file_size'         => $audioFile->getSize(),
            'processing_status' => 'pending',
        ]);

        // Dispatch processing job
        \App\Jobs\ProcessAudioBook::dispatch($audiobook, $chapter);

        return $chapter;
    }

    /**
     * Generate signed streaming URL for a specific chapter.
     */
    public function getChapterStreamingUrl(AudioBookChapter $chapter): string
    {
        if (!$chapter->isReady()) {
            throw new \RuntimeException('Chapter is not ready for streaming yet.');
        }

        return $chapter->getStreamingUrl(15);
    }

    /**
     * Reorder chapters.
     */
    public function reorderChapters(AudioBook $audiobook, array $chapterOrder): void
    {
        // $chapterOrder = [[id => 1, chapter_number => 1], ...]
        DB::transaction(function () use ($audiobook, $chapterOrder): void {
            foreach ($chapterOrder as $item) {
                AudioBookChapter::where('audiobook_id', $audiobook->id)
                    ->where('id', $item['id'])
                    ->update(['chapter_number' => $item['chapter_number']]);
            }
        });

        Cache::forget("tenant:{$audiobook->tenant_id}:audiobook:{$audiobook->id}");
    }

    /**
     * Delete a single chapter.
     */
    public function deleteChapter(AudioBookChapter $chapter): bool
    {
        $this->storageService->delete($chapter->s3_key);
        return (bool) $chapter->delete();
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function generateUniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title);
        $slug     = $baseSlug;
        $counter  = 1;

        while (AudioBook::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', app('tenant')->id)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
