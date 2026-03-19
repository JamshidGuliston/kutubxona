<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

/**
 * ApiResponseTrait
 *
 * Provides a uniform JSON response format for all API controllers.
 *
 * Response envelope:
 * {
 *   "success": true|false,
 *   "data": mixed,
 *   "message": string,
 *   "errors": array|null,      (only on error)
 *   "meta": { request_id, timestamp, version, ...pagination }
 * }
 */
trait ApiResponseTrait
{
    /**
     * 200 OK — general success with data payload.
     */
    protected function success(
        mixed $data,
        string $message = 'Success',
        int $code = 200,
        array $extra = [],
    ): JsonResponse {
        return response()->json(array_merge([
            'success' => true,
            'data'    => $data instanceof JsonResource || $data instanceof ResourceCollection
                ? $data->resolve()
                : $data,
            'message' => $message,
            'meta'    => $this->baseMeta(),
        ], $extra), $code);
    }

    /**
     * 201 Created — resource was successfully created.
     */
    protected function created(mixed $data, string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * 204 No Content — action was successful but there is nothing to return.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Error response — maps to the given HTTP status code.
     *
     * @param array<string, mixed> $errors   Validation or field-level errors
     */
    protected function error(
        string $message,
        int $code = 400,
        array $errors = [],
        ?string $errorCode = null,
    ): JsonResponse {
        $body = [
            'success' => false,
            'data'    => null,
            'message' => $message,
            'meta'    => $this->baseMeta(),
        ];

        if (! empty($errors)) {
            $body['errors'] = $errors;
        }

        if ($errorCode !== null) {
            $body['code'] = $errorCode;
        }

        return response()->json($body, $code);
    }

    /**
     * Paginated success response — enriches meta with pagination information.
     */
    protected function paginated(
        mixed $data,
        LengthAwarePaginator $paginator,
        string $message = 'Data retrieved successfully',
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => $data instanceof JsonResource || $data instanceof ResourceCollection
                ? $data->resolve()
                : $data,
            'message' => $message,
            'meta'    => array_merge($this->baseMeta(), [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                    'has_more'     => $paginator->hasMorePages(),
                    'links'        => [
                        'first' => $paginator->url(1),
                        'prev'  => $paginator->previousPageUrl(),
                        'next'  => $paginator->nextPageUrl(),
                        'last'  => $paginator->url($paginator->lastPage()),
                    ],
                ],
            ]),
        ]);
    }

    /**
     * 401 Unauthorized.
     */
    protected function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return $this->error($message, 401, errorCode: 'UNAUTHENTICATED');
    }

    /**
     * 403 Forbidden.
     */
    protected function forbidden(string $message = 'This action is unauthorized.'): JsonResponse
    {
        return $this->error($message, 403, errorCode: 'FORBIDDEN');
    }

    /**
     * 404 Not Found.
     */
    protected function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return $this->error($message, 404, errorCode: 'NOT_FOUND');
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function baseMeta(): array
    {
        return [
            'request_id' => request()->header('X-Request-ID', (string) Str::uuid()),
            'timestamp'  => now()->toIso8601String(),
            'version'    => 'v1',
        ];
    }
}
