<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Search;

use App\Application\Services\SearchService;
use App\Interfaces\Http\Controllers\BaseController;
use App\Interfaces\Http\Resources\Book\BookResource;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SearchController extends BaseController
{
    use ApiResponseTrait;

    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    /**
     * GET /api/v1/search
     *
     * Full-text search across books within the current tenant.
     * Supports pagination and basic filters.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'          => ['required', 'string', 'min:2', 'max:255'],
            'category_id'=> ['nullable', 'integer', 'exists:categories,id'],
            'author_id'  => ['nullable', 'integer', 'exists:authors,id'],
            'language'   => ['nullable', 'string', 'max:10'],
            'year_from'  => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'year_to'    => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'is_free'    => ['nullable', 'boolean'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:50'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = app('tenant')->id;
        $perPage  = (int) ($validated['per_page'] ?? 20);

        $results = $this->searchService->search(
            tenantId: $tenantId,
            query: $validated['q'],
            filters: array_filter(
                $validated,
                fn ($v, $k) => $v !== null && ! in_array($k, ['q', 'per_page', 'page'], true),
                ARRAY_FILTER_USE_BOTH,
            ),
            page: (int) ($validated['page'] ?? 1),
            perPage: $perPage,
        );

        // Log the search event asynchronously
        dispatch(new \App\Jobs\TrackAnalyticsEvent(
            tenantId: $tenantId,
            eventType: 'search',
            data: [
                'query'   => $validated['q'],
                'results' => $results->total(),
                'filters' => array_filter($validated, fn ($k) => ! in_array($k, ['q', 'per_page', 'page'], true), ARRAY_FILTER_USE_KEY),
            ],
            userId: auth()->id(),
        ))->onQueue('analytics');

        return $this->paginated(
            BookResource::collection($results->items()),
            $results,
            "Search results for \"{$validated['q']}\"",
        );
    }

    /**
     * GET /api/v1/search/autocomplete?q=...
     *
     * Returns quick suggestions (titles + authors) for type-ahead UI.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'     => ['required', 'string', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $tenantId = app('tenant')->id;
        $limit    = (int) ($validated['limit'] ?? 10);

        $suggestions = $this->searchService->autocomplete(
            tenantId: $tenantId,
            query: $validated['q'],
            limit: $limit,
        );

        return $this->success($suggestions, 'Autocomplete suggestions');
    }

    /**
     * POST /api/v1/search/advanced
     *
     * Advanced search with multiple filter combinations.
     */
    public function advanced(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'              => ['nullable', 'string', 'max:255'],
            'title'          => ['nullable', 'string', 'max:255'],
            'author'         => ['nullable', 'string', 'max:255'],
            'publisher'      => ['nullable', 'string', 'max:255'],
            'isbn'           => ['nullable', 'string', 'max:20'],
            'category_ids'   => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'genre_ids'      => ['nullable', 'array'],
            'genre_ids.*'    => ['integer', 'exists:genres,id'],
            'tag_slugs'      => ['nullable', 'array'],
            'tag_slugs.*'    => ['string', 'max:120'],
            'language'       => ['nullable', 'string', 'max:10'],
            'year_from'      => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'year_to'        => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'min_rating'     => ['nullable', 'numeric', 'min:1', 'max:5'],
            'is_free'        => ['nullable', 'boolean'],
            'is_downloadable'=> ['nullable', 'boolean'],
            'sort'           => ['nullable', 'string', Rule::in(['relevance', 'title', 'created_at', 'average_rating', 'download_count', 'published_year'])],
            'order'          => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:50'],
            'page'           => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = app('tenant')->id;
        $perPage  = (int) ($validated['per_page'] ?? 20);

        $results = $this->searchService->advancedSearch(
            tenantId: $tenantId,
            filters: $validated,
            page: (int) ($validated['page'] ?? 1),
            perPage: $perPage,
        );

        return $this->paginated(
            BookResource::collection($results->items()),
            $results,
            'Advanced search results',
        );
    }
}
