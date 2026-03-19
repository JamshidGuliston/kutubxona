<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Reading;

use App\Application\Services\ReadingService;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Reading", description="Reading progress, bookmarks and highlights")
 */
final class ReadingProgressController extends BaseController
{
    public function __construct(
        private readonly ReadingService $readingService,
    ) {}

    /**
     * @OA\Get(
     *   path="/api/v1/reading/progress",
     *   tags={"Reading"},
     *   summary="Get all reading progress for current user",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Reading progress list")
     * )
     */
    public function index(): JsonResponse
    {
        $user     = auth()->user();
        $progress = $this->readingService->getUserProgress($user);

        return $this->successResponse(
            data: $progress,
            message: 'Reading progress retrieved'
        );
    }

    /**
     * @OA\Put(
     *   path="/api/v1/reading/progress/{bookId}",
     *   tags={"Reading"},
     *   summary="Update reading progress for a book",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="bookId", in="path", required=true),
     *   @OA\RequestBody(
     *     @OA\JsonContent(
     *       @OA\Property(property="current_page", type="integer"),
     *       @OA\Property(property="current_cfi", type="string"),
     *       @OA\Property(property="percentage", type="number"),
     *       @OA\Property(property="reading_time", type="integer", description="Seconds spent in this session")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Progress updated")
     * )
     */
    public function updateBookProgress(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'current_page' => ['nullable', 'integer', 'min:1'],
            'current_cfi'  => ['nullable', 'string', 'max:500'],
            'percentage'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reading_time' => ['nullable', 'integer', 'min:0'],
        ]);

        $user     = auth()->user();
        $progress = $this->readingService->updateProgress($user, $bookId, $validated);

        return $this->successResponse(
            data: $progress,
            message: 'Reading progress updated'
        );
    }

    /**
     * @OA\Put(
     *   path="/api/v1/reading/audio-progress/{audiobookId}",
     *   tags={"Reading"},
     *   summary="Update listening progress for an audiobook",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Progress updated")
     * )
     */
    public function updateAudioProgress(Request $request, int $audiobookId): JsonResponse
    {
        $validated = $request->validate([
            'current_chapter'  => ['nullable', 'integer', 'min:1'],
            'current_position' => ['nullable', 'integer', 'min:0'],
            'percentage'       => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $user     = auth()->user();
        $progress = $this->readingService->updateAudioProgress($user, $audiobookId, $validated);

        return $this->successResponse(
            data: $progress,
            message: 'Listening progress updated'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/reading/history",
     *   tags={"Reading"},
     *   summary="Get reading history",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(response=200, description="Reading history")
     * )
     */
    public function history(): JsonResponse
    {
        $user    = auth()->user();
        $history = $this->readingService->getReadingHistory($user, 30);

        return $this->successResponse(
            data: $history,
            message: 'Reading history retrieved'
        );
    }

    // ─── Bookmarks ───────────────────────────────────────────────────────────────

    /**
     * @OA\Get(path="/api/v1/books/{bookId}/bookmarks", tags={"Reading"}, summary="List bookmarks for a book", security={{"bearerAuth":{}}})
     */
    public function listBookmarks(int $bookId): JsonResponse
    {
        $user      = auth()->user();
        $bookmarks = $this->readingService->getBookmarks($user, $bookId);

        return $this->successResponse(data: $bookmarks, message: 'Bookmarks retrieved');
    }

    /**
     * @OA\Post(path="/api/v1/books/{bookId}/bookmarks", tags={"Reading"}, summary="Create a bookmark", security={{"bearerAuth":{}}})
     */
    public function createBookmark(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'page'  => ['nullable', 'integer', 'min:1'],
            'cfi'   => ['nullable', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:255'],
            'note'  => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'in:yellow,green,blue,pink,purple,red,orange'],
        ]);

        $user     = auth()->user();
        $bookmark = $this->readingService->createBookmark($user, $bookId, $validated);

        return $this->successResponse(data: $bookmark, message: 'Bookmark created', status: 201);
    }

    /**
     * @OA\Put(path="/api/v1/books/{bookId}/bookmarks/{id}", tags={"Reading"}, summary="Update a bookmark", security={{"bearerAuth":{}}})
     */
    public function updateBookmark(Request $request, int $bookId, \App\Domain\Reading\Models\Bookmark $bookmark): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'note'  => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'in:yellow,green,blue,pink,purple,red,orange'],
        ]);

        $user     = auth()->user();
        $bookmark = $this->readingService->updateBookmark($user, $bookmark, $validated);

        return $this->successResponse(data: $bookmark, message: 'Bookmark updated');
    }

    /**
     * @OA\Delete(path="/api/v1/books/{bookId}/bookmarks/{id}", tags={"Reading"}, summary="Delete a bookmark", security={{"bearerAuth":{}}})
     */
    public function deleteBookmark(int $bookId, \App\Domain\Reading\Models\Bookmark $bookmark): JsonResponse
    {
        $user = auth()->user();
        $this->readingService->deleteBookmark($user, $bookmark);

        return $this->successResponse(data: null, message: 'Bookmark deleted');
    }

    // ─── Highlights ──────────────────────────────────────────────────────────────

    /**
     * @OA\Get(path="/api/v1/books/{bookId}/highlights", tags={"Reading"}, summary="List highlights", security={{"bearerAuth":{}}})
     */
    public function listHighlights(Request $request, int $bookId): JsonResponse
    {
        $user       = auth()->user();
        $color      = $request->query('color');
        $highlights = $this->readingService->getHighlights($user, $bookId, $color);

        return $this->successResponse(data: $highlights, message: 'Highlights retrieved');
    }

    /**
     * @OA\Post(path="/api/v1/books/{bookId}/highlights", tags={"Reading"}, summary="Create a highlight", security={{"bearerAuth":{}}})
     */
    public function createHighlight(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'page'          => ['nullable', 'integer', 'min:1'],
            'cfi_start'     => ['nullable', 'string', 'max:500'],
            'cfi_end'       => ['nullable', 'string', 'max:500'],
            'selected_text' => ['required', 'string', 'max:5000'],
            'note'          => ['nullable', 'string'],
            'color'         => ['nullable', 'string', 'in:yellow,green,blue,pink,purple'],
        ]);

        $user      = auth()->user();
        $highlight = $this->readingService->createHighlight($user, $bookId, $validated);

        return $this->successResponse(data: $highlight, message: 'Highlight created', status: 201);
    }

    /**
     * @OA\Put(path="/api/v1/books/{bookId}/highlights/{id}", tags={"Reading"}, summary="Update a highlight", security={{"bearerAuth":{}}})
     */
    public function updateHighlight(Request $request, int $bookId, \App\Domain\Reading\Models\Highlight $highlight): JsonResponse
    {
        $validated = $request->validate([
            'note'  => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'in:yellow,green,blue,pink,purple'],
        ]);

        $user      = auth()->user();
        $highlight = $this->readingService->updateHighlight($user, $highlight, $validated);

        return $this->successResponse(data: $highlight, message: 'Highlight updated');
    }

    /**
     * @OA\Delete(path="/api/v1/books/{bookId}/highlights/{id}", tags={"Reading"}, summary="Delete a highlight", security={{"bearerAuth":{}}})
     */
    public function deleteHighlight(int $bookId, \App\Domain\Reading\Models\Highlight $highlight): JsonResponse
    {
        $user = auth()->user();
        $this->readingService->deleteHighlight($user, $highlight);

        return $this->successResponse(data: null, message: 'Highlight deleted');
    }
}
