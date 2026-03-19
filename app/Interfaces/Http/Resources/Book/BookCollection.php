<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Book;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class BookCollection extends ResourceCollection
{
    public string $collects = BookResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    public function withResponse(Request $request, \Illuminate\Http\JsonResponse $response): void
    {
        $data = $response->getData(true);
        $data['success'] = true;
        $data['message'] = 'Books retrieved successfully';
        $response->setData($data);
    }
}
