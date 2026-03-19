<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Library;

use App\Domain\Library\Models\Author;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Resources\Library\AuthorResource;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class AuthorController extends BaseController
{
    use ApiResponseTrait;

    /**
     * GET /api/v1/library/authors
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'      => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'sort'        => ['nullable', 'string', Rule::in(['name', 'created_at'])],
            'order'       => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Author::query()
            ->withCount('books');

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        if (! empty($validated['nationality'])) {
            $query->where('nationality', $validated['nationality']);
        }

        $sort  = $validated['sort']  ?? 'name';
        $order = $validated['order'] ?? 'asc';
        $query->orderBy($sort, $order);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $authors = $query->paginate($perPage);

        return $this->paginated(
            AuthorResource::collection($authors->items()),
            $authors,
            'Authors retrieved successfully',
        );
    }

    /**
     * GET /api/v1/library/authors/{id}
     */
    public function show(int $id): JsonResponse
    {
        $author = Author::query()
            ->withCount('books')
            ->find($id);

        if ($author === null) {
            return $this->notFound('Author not found.');
        }

        return $this->success(
            new AuthorResource($author),
            'Author retrieved successfully',
        );
    }

    /**
     * POST /api/v1/library/authors
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Author::class);

        $tenantId = app('tenant')->id;

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:300'],
            'bio'         => ['nullable', 'string', 'max:10000'],
            'birth_date'  => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'website'     => ['nullable', 'url', 'max:500'],
            'photo'       => ['nullable', 'image', 'max:5120'],  // 5 MB
        ]);

        return DB::transaction(function () use ($validated, $request, $tenantId): JsonResponse {
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store(
                    "tenants/{$tenantId}/authors",
                    's3'
                );
            }

            $author = Author::create(array_merge(
                $validated,
                ['tenant_id' => $tenantId, 'photo_path' => $photoPath],
            ));

            return $this->created(
                new AuthorResource($author),
                'Author created successfully',
            );
        });
    }

    /**
     * PUT /api/v1/library/authors/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $author = Author::find($id);

        if ($author === null) {
            return $this->notFound('Author not found.');
        }

        $this->authorize('update', $author);

        $tenantId = app('tenant')->id;

        $validated = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:300'],
            'bio'         => ['nullable', 'string', 'max:10000'],
            'birth_date'  => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'website'     => ['nullable', 'url', 'max:500'],
            'photo'       => ['nullable', 'image', 'max:5120'],
        ]);

        return DB::transaction(function () use ($validated, $request, $author, $tenantId): JsonResponse {
            if ($request->hasFile('photo')) {
                // Remove old photo
                if ($author->photo_path) {
                    Storage::disk('s3')->delete($author->photo_path);
                }
                $validated['photo_path'] = $request->file('photo')->store(
                    "tenants/{$tenantId}/authors",
                    's3'
                );
            }

            $author->update($validated);

            return $this->success(
                new AuthorResource($author->fresh()),
                'Author updated successfully',
            );
        });
    }

    /**
     * DELETE /api/v1/library/authors/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $author = Author::find($id);

        if ($author === null) {
            return $this->notFound('Author not found.');
        }

        $this->authorize('delete', $author);

        $author->delete();

        return $this->noContent();
    }
}
