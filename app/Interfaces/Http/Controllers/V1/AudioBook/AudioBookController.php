<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\AudioBook;

use App\Application\Services\AudioBookService;
use App\Domain\AudioBook\Models\AudioBook;
use App\Domain\AudioBook\Models\AudioBookChapter;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Audiobooks", description="Audiobook catalog management")
 */
final class AudioBookController extends BaseController
{
    public function __construct(
        private readonly AudioBookService $audioBookService,
    ) {}

    /**
     * @OA\Get(path="/api/v1/audiobooks", tags={"Audiobooks"}, summary="List audiobooks")
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'author_id', 'language', 'is_free', 'sort', 'order',
        ]);

        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);

        $paginator = $this->audioBookService->getAudioBooks($filters, $page, $perPage);

        return $this->paginatedResponse(
            data: $paginator->items(),
            paginator: $paginator,
            message: 'Audiobooks retrieved'
        );
    }

    /**
     * @OA\Get(path="/api/v1/audiobooks/{id}", tags={"Audiobooks"}, summary="Get audiobook with chapters")
     */
    public function show(int $id): JsonResponse
    {
        $audiobook = $this->audioBookService->getAudioBook($id);

        if (!$audiobook) {
            return $this->errorResponse('Audiobook not found.', 404);
        }

        return $this->successResponse(
            data: $audiobook,
            message: 'Audiobook retrieved'
        );
    }

    /**
     * @OA\Post(path="/api/v1/audiobooks", tags={"Audiobooks"}, summary="Create audiobook", security={{"bearerAuth":{}}})
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AudioBook::class);

        $validated = $request->validate([
            'title'          => ['required', 'string', 'max:500'],
            'language'       => ['required', 'string', 'in:uz,ru,en'],
            'description'    => ['nullable', 'string'],
            'narrator'       => ['nullable', 'string', 'max:255'],
            'author_id'      => ['nullable', 'integer', 'exists:authors,id'],
            'publisher_id'   => ['nullable', 'integer', 'exists:publishers,id'],
            'category_id'    => ['nullable', 'integer', 'exists:categories,id'],
            'book_id'        => ['nullable', 'integer', 'exists:books,id'],
            'published_year' => ['nullable', 'integer', 'min:1000', 'max:2099'],
            'is_featured'    => ['nullable', 'boolean'],
            'is_free'        => ['nullable', 'boolean'],
            'price'          => ['nullable', 'numeric', 'min:0'],
            'cover_image'    => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $audiobook = $this->audioBookService->createAudioBook(
            $validated,
            $request->file('cover_image')
        );

        return $this->successResponse(
            data: $audiobook,
            message: 'Audiobook created successfully',
            status: 201
        );
    }

    /**
     * @OA\Put(path="/api/v1/audiobooks/{id}", tags={"Audiobooks"}, summary="Update audiobook", security={{"bearerAuth":{}}})
     */
    public function update(Request $request, AudioBook $audiobook): JsonResponse
    {
        $this->authorize('update', $audiobook);

        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string'],
            'narrator'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'      => ['sometimes', 'in:draft,published,archived'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_free'     => ['sometimes', 'boolean'],
            'price'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $updated = $this->audioBookService->updateAudioBook($audiobook, $validated);

        return $this->successResponse(data: $updated, message: 'Audiobook updated');
    }

    /**
     * @OA\Delete(path="/api/v1/audiobooks/{id}", tags={"Audiobooks"}, summary="Delete audiobook", security={{"bearerAuth":{}}})
     */
    public function destroy(AudioBook $audiobook): JsonResponse
    {
        $this->authorize('delete', $audiobook);

        $this->audioBookService->deleteAudioBook($audiobook);

        return $this->successResponse(data: null, message: 'Audiobook deleted');
    }

    /**
     * @OA\Post(
     *   path="/api/v1/audiobooks/{id}/chapters",
     *   tags={"Audiobooks"},
     *   summary="Add a chapter to an audiobook",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=201, description="Chapter added and queued for processing")
     * )
     */
    public function addChapter(Request $request, AudioBook $audiobook): JsonResponse
    {
        $this->authorize('update', $audiobook);

        $validated = $request->validate([
            'title'          => ['nullable', 'string', 'max:500'],
            'chapter_number' => ['nullable', 'integer', 'min:1'],
            'audio_file'     => ['required', 'file', 'mimes:mp3,m4a,ogg,aac', 'max:307200'], // 300MB
        ]);

        $chapter = $this->audioBookService->addChapter(
            $audiobook,
            $request->file('audio_file'),
            $validated
        );

        return $this->successResponse(
            data: $chapter,
            message: 'Chapter uploaded and queued for processing.',
            status: 201
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/audiobooks/{id}/chapters/{chapterId}/stream",
     *   tags={"Audiobooks"},
     *   summary="Get streaming URL for a chapter",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Streaming URL generated")
     * )
     */
    public function streamChapter(AudioBook $audiobook, AudioBookChapter $chapter): JsonResponse
    {
        if ($chapter->audiobook_id !== $audiobook->id) {
            return $this->errorResponse('Chapter does not belong to this audiobook.', 404);
        }

        $this->authorize('view', $audiobook);

        $streamUrl = $this->audioBookService->getChapterStreamingUrl($chapter);

        return $this->successResponse(
            data: [
                'stream_url' => $streamUrl,
                'chapter_id' => $chapter->id,
                'title'      => $chapter->title,
                'duration'   => $chapter->duration,
                'expires_in' => 900,
            ],
            message: 'Stream URL generated'
        );
    }

    /**
     * @OA\Delete(
     *   path="/api/v1/audiobooks/{id}/chapters/{chapterId}",
     *   tags={"Audiobooks"},
     *   summary="Delete a chapter",
     *   security={{"bearerAuth":{}}}
     * )
     */
    public function deleteChapter(AudioBook $audiobook, AudioBookChapter $chapter): JsonResponse
    {
        $this->authorize('update', $audiobook);

        if ($chapter->audiobook_id !== $audiobook->id) {
            return $this->errorResponse('Chapter does not belong to this audiobook.', 404);
        }

        $this->audioBookService->deleteChapter($chapter);

        return $this->successResponse(data: null, message: 'Chapter deleted');
    }
}
