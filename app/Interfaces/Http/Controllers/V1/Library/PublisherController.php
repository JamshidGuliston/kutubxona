<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Library;

use App\Domain\Library\Models\Publisher;
use App\Interfaces\Http\Controllers\BaseController;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class PublisherController extends BaseController
{
    use ApiResponseTrait;

    /**
     * GET /api/v1/library/publishers
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'   => ['nullable', 'string', 'max:255'],
            'country'  => ['nullable', 'string', 'max:100'],
            'sort'     => ['nullable', 'string', Rule::in(['name', 'created_at', 'founded_year'])],
            'order'    => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Publisher::query()->withCount('books');

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        if (! empty($validated['country'])) {
            $query->where('country', $validated['country']);
        }

        $sort  = $validated['sort']  ?? 'name';
        $order = $validated['order'] ?? 'asc';
        $query->orderBy($sort, $order);

        $perPage    = (int) ($validated['per_page'] ?? 20);
        $publishers = $query->paginate($perPage);

        return $this->paginated(
            $publishers->items(),
            $publishers,
            'Publishers retrieved successfully',
        );
    }

    /**
     * GET /api/v1/library/publishers/{id}
     */
    public function show(int $id): JsonResponse
    {
        $publisher = Publisher::query()
            ->withCount('books')
            ->find($id);

        if ($publisher === null) {
            return $this->notFound('Publisher not found.');
        }

        return $this->success($publisher, 'Publisher retrieved successfully');
    }

    /**
     * POST /api/v1/library/publishers
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Publisher::class);

        $tenantId = app('tenant')->id;

        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:300'],
            'description'  => ['nullable', 'string', 'max:10000'],
            'website'      => ['nullable', 'url', 'max:500'],
            'country'      => ['nullable', 'string', 'max:100'],
            'founded_year' => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'logo'         => ['nullable', 'image', 'max:5120'],
        ]);

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store(
                "tenants/{$tenantId}/publishers",
                's3'
            );
        }

        $publisher = Publisher::create(array_merge(
            $validated,
            ['tenant_id' => $tenantId, 'logo_path' => $logoPath],
        ));

        return $this->created($publisher, 'Publisher created successfully');
    }

    /**
     * PUT /api/v1/library/publishers/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $publisher = Publisher::find($id);

        if ($publisher === null) {
            return $this->notFound('Publisher not found.');
        }

        $this->authorize('update', $publisher);

        $tenantId = app('tenant')->id;

        $validated = $request->validate([
            'name'         => ['sometimes', 'required', 'string', 'max:300'],
            'description'  => ['nullable', 'string', 'max:10000'],
            'website'      => ['nullable', 'url', 'max:500'],
            'country'      => ['nullable', 'string', 'max:100'],
            'founded_year' => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'logo'         => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('logo')) {
            if ($publisher->logo_path) {
                Storage::disk('s3')->delete($publisher->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store(
                "tenants/{$tenantId}/publishers",
                's3'
            );
        }

        $publisher->update($validated);

        return $this->success($publisher->fresh(), 'Publisher updated successfully');
    }

    /**
     * DELETE /api/v1/library/publishers/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $publisher = Publisher::find($id);

        if ($publisher === null) {
            return $this->notFound('Publisher not found.');
        }

        $this->authorize('delete', $publisher);

        $publisher->delete();

        return $this->noContent();
    }
}
