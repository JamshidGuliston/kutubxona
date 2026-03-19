<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Review;

use App\Domain\Book\Models\Book;
use App\Domain\Reading\Models\Review;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReviewController extends BaseController
{
    public function index(Request $request, Book $book): JsonResponse
    {
        $reviews = Review::with(['user:id,name,avatar'])
            ->where('book_id', $book->id)
            ->where('is_approved', true)
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->paginatedResponse(
            data: $reviews->items(),
            paginator: $reviews,
            message: 'Reviews retrieved successfully'
        );
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $validated = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'title'   => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = auth()->user();

        $existing = Review::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->first();

        if ($existing) {
            return $this->errorResponse('You have already reviewed this book.', 422);
        }

        $review = Review::create([
            'tenant_id'   => $user->tenant_id,
            'user_id'     => $user->id,
            'book_id'     => $book->id,
            'rating'      => $validated['rating'],
            'title'       => $validated['title'] ?? null,
            'content'     => $validated['content'] ?? null,
            'is_approved' => false,
        ]);

        $book->recalculateRating();

        return $this->successResponse(
            data: $review,
            message: 'Review submitted. It will appear after moderation.',
            status: 201
        );
    }

    public function update(Request $request, Book $book, Review $review): JsonResponse
    {
        if ($review->user_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'rating'  => ['sometimes', 'integer', 'min:1', 'max:5'],
            'title'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $review->update(array_merge($validated, ['is_approved' => false]));
        $book->recalculateRating();

        return $this->successResponse(data: $review->fresh(), message: 'Review updated');
    }

    public function destroy(Book $book, Review $review): JsonResponse
    {
        $user = auth()->user();

        if ($review->user_id !== $user->id && !$user->hasRole(['tenant_admin', 'super_admin'])) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $review->delete();
        $book->recalculateRating();

        return $this->successResponse(data: null, message: 'Review deleted');
    }
}
