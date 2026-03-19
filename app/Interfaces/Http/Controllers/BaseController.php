<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

abstract class BaseController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Return a standardized success response.
     */
    protected function successResponse(
        mixed $data,
        string $message = 'Success',
        int $status = 200,
        ?array $meta = null
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'meta'    => array_merge($this->defaultMeta(), $meta ?? []),
        ], $status);
    }

    /**
     * Return a standardized error response.
     */
    protected function errorResponse(
        string $message,
        int $status = 400,
        mixed $errors = null,
        ?string $code = null
    ): JsonResponse {
        $body = [
            'success' => false,
            'data'    => null,
            'message' => $message,
            'meta'    => $this->defaultMeta(),
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        if ($code !== null) {
            $body['code'] = $code;
        }

        return response()->json($body, $status);
    }

    /**
     * Return a paginated response with standardized meta.
     */
    protected function paginatedResponse(
        mixed $data,
        \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator,
        string $message = 'Data retrieved'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'meta'    => array_merge($this->defaultMeta(), [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
                'links'        => [
                    'first' => $paginator->url(1),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                    'last'  => $paginator->url($paginator->lastPage()),
                ],
            ]),
        ]);
    }

    private function defaultMeta(): array
    {
        return [
            'request_id' => request()->header('X-Request-ID', (string) Str::uuid()),
            'timestamp'  => now()->toIso8601String(),
            'version'    => 'v1',
        ];
    }
}
