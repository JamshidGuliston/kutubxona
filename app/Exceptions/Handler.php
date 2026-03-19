<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class Handler
{
    public function renderApiException(Throwable $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', (string) Str::uuid());

        $meta = [
            'request_id' => $requestId,
            'timestamp'  => now()->toIso8601String(),
        ];

        // Validation errors
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
                'meta'    => $meta,
            ], 422);
        }

        // Unauthenticated
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Unauthenticated. Please login.',
                'code'    => 'UNAUTHENTICATED',
                'meta'    => $meta,
            ], 401);
        }

        // Unauthorized
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'You are not authorized to perform this action.',
                'code'    => 'FORBIDDEN',
                'meta'    => $meta,
            ], 403);
        }

        // Model not found
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            $model = $e instanceof ModelNotFoundException
                ? class_basename($e->getModel())
                : 'Resource';

            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => "{$model} not found.",
                'code'    => 'NOT_FOUND',
                'meta'    => $meta,
            ], 404);
        }

        // HTTP exceptions
        if ($e instanceof HttpException) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage() ?: 'HTTP error.',
                'code'    => 'HTTP_ERROR',
                'meta'    => $meta,
            ], $e->getStatusCode());
        }

        // Generic server error
        $debug = config('app.debug');
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.',
            'code'    => 'SERVER_ERROR',
            'meta'    => array_merge($meta, $debug ? [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ] : []),
        ], 500);
    }
}
