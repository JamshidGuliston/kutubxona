<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Reading;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Reading\Models\Highlight
 */
final class HighlightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'book_id'       => $this->book_id,
            'page'          => $this->page,
            'cfi_start'     => $this->cfi_start,
            'cfi_end'       => $this->cfi_end,
            'selected_text' => $this->selected_text,
            'note'          => $this->note,
            'color'         => $this->color,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
