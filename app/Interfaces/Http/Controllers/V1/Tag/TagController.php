<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Tag;

use App\Domain\Library\Models\Tag;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class TagController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $tags = Tag::withCount('books')
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where('name', 'like', '%' . $request->search . '%')
            )
            ->orderByDesc('books_count')
            ->paginate($request->integer('per_page', 50));

        return $this->paginatedResponse(
            data: $tags->items(),
            paginator: $tags,
            message: 'Tags retrieved successfully'
        );
    }

    public function cloud(): JsonResponse
    {
        $tags = Tag::withCount('books')
            ->having('books_count', '>', 0)
            ->orderByDesc('books_count')
            ->limit(50)
            ->get(['id', 'name', 'slug', 'color']);

        return $this->successResponse(
            data: $tags,
            message: 'Tag cloud retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $slug = Str::slug($validated['name']);

        $tag = Tag::firstOrCreate(
            ['tenant_id' => app('tenant')->id, 'slug' => $slug],
            array_merge($validated, ['tenant_id' => app('tenant')->id, 'slug' => $slug])
        );

        return $this->successResponse(data: $tag, message: 'Tag created', status: 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $this->authorize('update', $tag);

        $validated = $request->validate([
            'name'  => ['sometimes', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $tag->update($validated);

        return $this->successResponse(data: $tag->fresh(), message: 'Tag updated');
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);
        $tag->books()->detach();
        $tag->delete();

        return $this->successResponse(data: null, message: 'Tag deleted');
    }
}
