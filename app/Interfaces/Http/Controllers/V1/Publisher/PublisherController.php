<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Publisher;

use App\Domain\Library\Models\Publisher;
use App\Interfaces\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @OA\Tag(name="Publishers", description="Publisher management")
 */
final class PublisherController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $publishers = Publisher::withCount('books')
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where('name', 'like', '%' . $request->search . '%')
            )
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return $this->paginatedResponse(
            data: $publishers->items(),
            paginator: $publishers,
            message: 'Publishers retrieved successfully'
        );
    }

    public function show(Publisher $publisher): JsonResponse
    {
        $publisher->loadCount('books');

        return $this->successResponse(
            data: $publisher,
            message: 'Publisher retrieved successfully'
        );
    }

    public function books(Request $request, Publisher $publisher): JsonResponse
    {
        $books = $publisher->books()
            ->published()
            ->with(['author', 'categories'])
            ->paginate($request->integer('per_page', 20));

        return $this->paginatedResponse(
            data: $books->items(),
            paginator: $books,
            message: 'Publisher books retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Publisher::class);

        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url', 'max:255'],
            'founded' => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'logo'    => ['nullable', 'image', 'max:2048'],
        ]);

        $validated['tenant_id'] = app('tenant')->id;
        $validated['slug']      = Str::slug($validated['name']) . '-' . uniqid();

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store(
                'tenants/' . app('tenant')->id . '/publishers',
                's3'
            );
        }

        $publisher = Publisher::create($validated);

        return $this->successResponse(data: $publisher, message: 'Publisher created', status: 201);
    }

    public function update(Request $request, Publisher $publisher): JsonResponse
    {
        $this->authorize('update', $publisher);

        $validated = $request->validate([
            'name'    => ['sometimes', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'founded' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:' . date('Y')],
        ]);

        $publisher->update($validated);

        return $this->successResponse(data: $publisher->fresh(), message: 'Publisher updated');
    }

    public function destroy(Publisher $publisher): JsonResponse
    {
        $this->authorize('delete', $publisher);
        $publisher->delete();

        return $this->successResponse(data: null, message: 'Publisher deleted');
    }
}
