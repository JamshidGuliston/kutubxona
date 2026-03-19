<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Category;

use App\Domain\Library\Models\Category;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @OA\Tag(name="Categories", description="Category management")
 */
final class CategoryController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::withCount('books')
            ->when(
                $request->filled('parent_id'),
                fn ($q) => $q->where('parent_id', $request->parent_id),
                fn ($q) => $q->whereNull('parent_id')
            )
            ->when($request->boolean('with_children'), fn ($q) => $q->with('children'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->successResponse(
            data: $categories,
            message: 'Categories retrieved successfully'
        );
    }

    public function show(Category $category): JsonResponse
    {
        $category->load(['children', 'parent'])->loadCount('books');

        return $this->successResponse(
            data: $category,
            message: 'Category retrieved successfully'
        );
    }

    public function books(Request $request, Category $category): JsonResponse
    {
        $books = $category->books()
            ->published()
            ->with(['author', 'tags'])
            ->orderByDesc('average_rating')
            ->paginate($request->integer('per_page', 20));

        return $this->paginatedResponse(
            data: $books->items(),
            paginator: $books,
            message: 'Category books retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'parent_id'  => ['nullable', 'integer', 'exists:categories,id'],
            'icon'       => ['nullable', 'string', 'max:100'],
            'color'      => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        $validated['tenant_id'] = app('tenant')->id;
        $validated['slug']      = Str::slug($validated['name']) . '-' . uniqid();

        $category = Category::create($validated);

        return $this->successResponse(data: $category, message: 'Category created', status: 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'parent_id'  => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'icon'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'color'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        $category->update($validated);

        return $this->successResponse(data: $category->fresh(), message: 'Category updated');
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        if ($category->children()->exists()) {
            return $this->errorResponse('Cannot delete category with subcategories.', 422);
        }

        $category->delete();

        return $this->successResponse(data: null, message: 'Category deleted');
    }
}
