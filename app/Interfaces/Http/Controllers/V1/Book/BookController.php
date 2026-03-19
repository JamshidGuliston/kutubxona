<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Book;

use App\Application\DTOs\Book\CreateBookDTO;
use App\Application\DTOs\Book\UpdateBookDTO;
use App\Application\Services\BookService;
use App\Application\Services\SearchService;
use App\Domain\Book\Models\Book;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Requests\Book\CreateBookRequest;
use App\Interfaces\Http\Resources\Book\BookCollection;
use App\Interfaces\Http\Resources\Book\BookResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Books", description="Book catalog management")
 */
final class BookController extends BaseController
{
    public function __construct(
        private readonly BookService    $bookService,
        private readonly SearchService  $searchService,
    ) {}

    /**
     * @OA\Get(
     *   path="/api/v1/books",
     *   tags={"Books"},
     *   summary="List books with filters and pagination",
     *   @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", maximum=100)),
     *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="author_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="category_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="language", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", enum={"title","created_at","download_count","average_rating"})),
     *   @OA\Parameter(name="order", in="query", @OA\Schema(type="string", enum={"asc","desc"})),
     *   @OA\Response(response=200, description="Books list")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'author_id', 'publisher_id', 'category_id',
            'tag', 'language', 'year_from', 'year_to',
            'is_featured', 'is_free', 'sort', 'order',
        ]);

        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);

        // If search query provided, delegate to search service
        if (!empty($filters['search'])) {
            $paginator = $this->searchService->search(
                $filters['search'],
                $filters,
                'books',
                $page,
                $perPage
            )['books'];
        } else {
            $paginator = $this->bookService->getBooks($filters, $page, $perPage);
        }

        return $this->paginatedResponse(
            data: BookResource::collection($paginator->items()),
            paginator: $paginator,
            message: 'Books retrieved successfully'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/books/{id}",
     *   tags={"Books"},
     *   summary="Get book details",
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *   @OA\Response(response=200, description="Book detail"),
     *   @OA\Response(response=404, description="Book not found")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $book = $this->bookService->getBook($id);

        if (!$book) {
            return $this->errorResponse('Book not found.', 404);
        }

        // Append user-specific data if authenticated
        $userProgress = null;
        $isFavorited  = false;

        if (auth()->check()) {
            $user         = auth()->user();
            $userProgress = $book->readingProgress()->where('user_id', $user->id)->first();
            $isFavorited  = $book->favorites()->where('user_id', $user->id)->exists();
        }

        $resource = new BookResource($book);
        $resource->additional([
            'user_progress' => $userProgress,
            'is_favorited'  => $isFavorited,
        ]);

        return $this->successResponse(
            data: $resource,
            message: 'Book retrieved'
        );
    }

    /**
     * @OA\Post(
     *   path="/api/v1/books",
     *   tags={"Books"},
     *   summary="Create a new book",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *       mediaType="multipart/form-data",
     *       @OA\Schema(
     *         required={"title", "language"},
     *         @OA\Property(property="title", type="string"),
     *         @OA\Property(property="author_id", type="integer"),
     *         @OA\Property(property="language", type="string"),
     *         @OA\Property(property="description", type="string"),
     *         @OA\Property(property="book_file", type="string", format="binary"),
     *         @OA\Property(property="cover_image", type="string", format="binary")
     *       )
     *     )
     *   ),
     *   @OA\Response(response=201, description="Book created"),
     *   @OA\Response(response=422, description="Validation error"),
     *   @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function store(CreateBookRequest $request): JsonResponse
    {
        $this->authorize('create', Book::class);

        $dto  = CreateBookDTO::fromRequest($request);
        $book = $this->bookService->createBook($dto);

        return $this->successResponse(
            data: new BookResource($book),
            message: 'Book created successfully. File is being processed.',
            status: 201
        );
    }

    /**
     * @OA\Put(
     *   path="/api/v1/books/{id}",
     *   tags={"Books"},
     *   summary="Update a book",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true),
     *   @OA\Response(response=200, description="Book updated"),
     *   @OA\Response(response=403, description="Forbidden"),
     *   @OA\Response(response=404, description="Not found")
     * )
     */
    public function update(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $validated = $request->validate([
            'title'          => ['sometimes', 'string', 'max:500'],
            'author_id'      => ['sometimes', 'nullable', 'integer', 'exists:authors,id'],
            'publisher_id'   => ['sometimes', 'nullable', 'integer', 'exists:publishers,id'],
            'category_id'    => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'subtitle'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'description'    => ['sometimes', 'nullable', 'string'],
            'isbn'           => ['sometimes', 'nullable', 'string', 'max:20'],
            'language'       => ['sometimes', 'string', 'in:uz,ru,en,fr,de,ar'],
            'published_year' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:2099'],
            'edition'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'pages'          => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_featured'    => ['sometimes', 'boolean'],
            'is_downloadable'=> ['sometimes', 'boolean'],
            'is_free'        => ['sometimes', 'boolean'],
            'price'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status'         => ['sometimes', 'in:draft,published,archived'],
            'tag_ids'        => ['sometimes', 'array'],
            'tag_ids.*'      => ['integer', 'exists:tags,id'],
            'category_ids'   => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'book_file'      => ['sometimes', 'file', 'max:102400'],
            'cover_image'    => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $dto         = UpdateBookDTO::fromArray(
            $validated,
            $request->file('book_file'),
            $request->file('cover_image')
        );
        $updatedBook = $this->bookService->updateBook($book, $dto);

        return $this->successResponse(
            data: new BookResource($updatedBook),
            message: 'Book updated successfully'
        );
    }

    /**
     * @OA\Delete(
     *   path="/api/v1/books/{id}",
     *   tags={"Books"},
     *   summary="Delete a book",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true),
     *   @OA\Response(response=200, description="Book deleted"),
     *   @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function destroy(Book $book): JsonResponse
    {
        $this->authorize('delete', $book);

        $this->bookService->deleteBook($book);

        return $this->successResponse(
            data: null,
            message: 'Book deleted successfully'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/books/{id}/download",
     *   tags={"Books"},
     *   summary="Get signed download URL for a book file",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true),
     *   @OA\Parameter(name="file_type", in="query", @OA\Schema(type="string", enum={"pdf","epub"})),
     *   @OA\Response(response=200, description="Download URL generated"),
     *   @OA\Response(response=403, description="Download not allowed")
     * )
     */
    public function download(Request $request, Book $book): JsonResponse
    {
        $this->authorize('download', $book);

        $fileType = $request->query('file_type', 'pdf');
        $urlData  = $this->bookService->getDownloadUrl($book, $fileType);

        return $this->successResponse(
            data: $urlData,
            message: 'Download URL generated'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/books/{id}/stream",
     *   tags={"Books"},
     *   summary="Get signed streaming URL for reading",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true),
     *   @OA\Response(response=200, description="Stream URL generated")
     * )
     */
    public function stream(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $fileType  = $request->query('file_type', 'pdf');
        $streamUrl = $this->bookService->getStreamingUrl($book, $fileType);

        return $this->successResponse(
            data: ['stream_url' => $streamUrl, 'expires_in' => 900],
            message: 'Stream URL generated'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/books/popular",
     *   tags={"Books"},
     *   summary="Get popular books",
     *   @OA\Response(response=200, description="Popular books list")
     * )
     */
    public function popular(): JsonResponse
    {
        $books = $this->bookService->getPopularBooks(20);

        return $this->successResponse(
            data: BookResource::collection($books),
            message: 'Popular books retrieved'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/books/featured",
     *   tags={"Books"},
     *   summary="Get featured books",
     *   @OA\Response(response=200, description="Featured books list")
     * )
     */
    public function featured(): JsonResponse
    {
        $books = $this->bookService->getFeaturedBooks(10);

        return $this->successResponse(
            data: BookResource::collection($books),
            message: 'Featured books retrieved'
        );
    }

    /**
     * @OA\Get(
     *   path="/api/v1/books/new-arrivals",
     *   tags={"Books"},
     *   summary="Get recently added books",
     *   @OA\Response(response=200, description="New arrivals list")
     * )
     */
    public function newArrivals(): JsonResponse
    {
        $books = $this->bookService->getNewArrivals(20);

        return $this->successResponse(
            data: BookResource::collection($books),
            message: 'New arrivals retrieved'
        );
    }
}
