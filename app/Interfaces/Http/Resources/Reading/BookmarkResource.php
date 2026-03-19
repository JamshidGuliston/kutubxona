<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Reading;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Reading\Models\Bookmark
 */
final class BookmarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'book_id'    => $this->book_id,
            'page'       => $this->page,
            'cfi'        => $this->cfi,
            'title'      => $this->title,
            'note'       => $this->note,
            'color'      => $this->color,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
