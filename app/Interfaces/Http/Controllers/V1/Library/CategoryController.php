<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Library;

use App\Domain\Library\Models\Category;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Resources\Library\CategoryResource;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CategoryController extends BaseController
{
    use ApiResponseTrait;

    /**
     * GET /api/v1/library/categories
     * Returns flat paginated list; use ?tree=1 for the nested tree.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tree'      => ['nullable', 'boolean'],
            'active'    => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // Return nested tree structure
        if (filter_var($validated['tree'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $roots = Category::query()
                ->with('allChildren')
                ->whereNull('parent_id')
                ->when(isset($validated['active']), fn ($q) => $q->where('is_active', (bool) $validated['active']))
                ->ordered()
                ->get();

            return $this->success(
                CategoryResource::collection($roots),
                'Category tree retrieved successfully',
            );
        }

        $query = Category::query()
            ->withCount('books')
            ->when(
                isset($validated['active']),
                fn ($q) => $q->where('is_active', (bool) $validated['active']),
            )
            ->when(
                isset($validated['parent_id']),
                fn ($q) => $q->where('parent_id', $validated['parent_id']),
                fn ($q) => $q->whereNull('parent_id'),
            )
            ->ordered();

        $perPage    = (int) ($validated['per_page'] ?? 50);
        $categories = $query->paginate($perPage);

        return $this->paginated(
            CategoryResource::collection($categories->items()),
            $categories,
            'Categories retrieved successfully',
        );
    }

    /**
     * GET /api/v1/library/categories/{id}
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::query()
            ->with(['parent', 'children', 'allChildren'])
            ->withCount('books')
            ->find($id);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        return $this->success(
            new CategoryResource($category),
            'Category retrieved successfully',
        );
    }

    /**
     * POST /api/v1/library/categories
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $tenantId = app('tenant')->id;

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'parent_id'   => ['nullable', 'integer', 'exists:categories,id'],
            'icon'        => ['nullable', 'string', 'max:255'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $category = Category::create(array_merge(
            $validated,
            ['tenant_id' => $tenantId],
        ));

        return $this->created(
            new CategoryResource($category->load('parent')),
            'Category created successfully',
        );
    }

    /**
     * PUT /api/v1/library/categories/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        $this->authorize('update', $category);

        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'parent_id'   => [
                'nullable',
                'integer',
                'exists:categories,id',
                // Prevent self-reference or circular nesting
                Rule::notIn([$category->id]),
            ],
            'icon'        => ['nullable', 'string', 'max:255'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $category->update($validated);

        return $this->success(
            new CategoryResource($category->fresh()->load(['parent', 'children'])),
            'Category updated successfully',
        );
    }

    /**
     * DELETE /api/v1/library/categories/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::find($id);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        $this->authorize('delete', $category);

        if ($category->children()->exists()) {
            return $this->error(
                'Cannot delete a category that has sub-categories. Remove children first.',
                409,
                errorCode: 'CATEGORY_HAS_CHILDREN',
            );
        }

        // Unlink books from this category before deleting
        DB::transaction(function () use ($category): void {
            $category->primaryBooks()->update(['category_id' => null]);
            $category->books()->detach();
            $category->delete();
        });

        return $this->noContent();
    }

    /**
     * GET /api/v1/library/categories/{id}/books
     * Returns books within this category and all its descendants.
     */
    public function books(int $id): JsonResponse
    {
        $category = Category::query()->with('allChildren')->find($id);

        if ($category === null) {
            return $this->notFound('Category not found.');
        }

        $descendantIds = $category->getDescendantIds();

        $books = \App\Domain\Book\Models\Book::query()
            ->with(['author', 'publisher', 'categories', 'tags'])
            ->published()
            ->where(function ($query) use ($descendantIds): void {
                $query->whereIn('category_id', $descendantIds)
                    ->orWhereHas('categories', fn ($q) => $q->whereIn('categories.id', $descendantIds));
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->paginated(
            \App\Interfaces\Http\Resources\Book\BookResource::collection($books->items()),
            $books,
            'Category books retrieved successfully',
        );
    }
}
