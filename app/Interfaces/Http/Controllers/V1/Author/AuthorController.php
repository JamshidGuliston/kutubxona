<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Author;

use App\Domain\Library\Models\Author;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(name="Authors", description="Author management")
 */
final class AuthorController extends BaseController
{
    /**
     * GET /api/v1/authors
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'      => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'sort'        => ['nullable', 'string', Rule::in(['name', 'created_at', 'books_count'])],
            'order'       => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Author::withCount('books');

        if (!empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        if (!empty($validated['nationality'])) {
            $query->where('nationality', $validated['nationality']);
        }

        $sortCol = $validated['sort'] ?? 'name';
        $sortDir = $validated['order'] ?? 'asc';
        $query->orderBy($sortCol, $sortDir);

        $authors = $query->paginate($validated['per_page'] ?? 20);

        return $this->paginatedResponse(
            data: $authors->items(),
            paginator: $authors,
            message: 'Authors retrieved successfully'
        );
    }

    /**
     * GET /api/v1/authors/{author}
     */
    public function show(Author $author): JsonResponse
    {
        $author->load(['books' => fn ($q) => $q->published()->limit(10)]);

        return $this->successResponse(
            data: $author,
            message: 'Author retrieved successfully'
        );
    }

    /**
     * GET /api/v1/authors/{author}/books
     */
    public function books(Request $request, Author $author): JsonResponse
    {
        $books = $author->books()
            ->published()
            ->with(['categories', 'tags'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginatedResponse(
            data: $books->items(),
            paginator: $books,
            message: 'Author books retrieved successfully'
        );
    }

    /**
     * POST /api/v1/authors
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Author::class);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'bio'         => ['nullable', 'string'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'birth_year'  => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'death_year'  => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'photo'       => ['nullable', 'image', 'max:2048'],
            'website'     => ['nullable', 'url', 'max:255'],
        ]);

        $validated['tenant_id'] = app('tenant')->id;
        $validated['slug']      = Str::slug($validated['name']) . '-' . uniqid();

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store(
                'tenants/' . app('tenant')->id . '/authors',
                's3'
            );
        }

        $author = Author::create($validated);

        return $this->successResponse(
            data: $author,
            message: 'Author created successfully',
            status: 201
        );
    }

    /**
     * PUT /api/v1/authors/{author}
     */
    public function update(Request $request, Author $author): JsonResponse
    {
        $this->authorize('update', $author);

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'bio'         => ['sometimes', 'nullable', 'string'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'birth_year'  => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'death_year'  => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'website'     => ['sometimes', 'nullable', 'url', 'max:255'],
        ]);

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store(
                'tenants/' . app('tenant')->id . '/authors',
                's3'
            );
        }

        $author->update(array_filter($validated, fn ($v) => $v !== null));

        return $this->successResponse(
            data: $author->fresh(),
            message: 'Author updated successfully'
        );
    }

    /**
     * DELETE /api/v1/authors/{author}
     */
    public function destroy(Author $author): JsonResponse
    {
        $this->authorize('delete', $author);
        $author->delete();

        return $this->successResponse(
            data: null,
            message: 'Author deleted successfully'
        );
    }
}
