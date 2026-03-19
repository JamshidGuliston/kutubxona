<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Reading;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Reading\Models\ReadingProgress
 */
final class ReadingProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'book_id'          => $this->book_id,
            'audiobook_id'     => $this->audiobook_id,
            'current_page'     => $this->current_page,
            'current_cfi'      => $this->current_cfi,
            'current_chapter'  => $this->current_chapter,
            'current_position' => $this->current_position,
            'percentage'       => (float) $this->percentage,
            'reading_time'     => $this->reading_time,
            'is_completed'     => $this->is_completed ?? false,
            'last_read_at'     => $this->last_read_at?->toIso8601String(),
            'completed_at'     => $this->completed_at?->toIso8601String(),

            'book' => $this->whenLoaded('book', fn () => [
                'id'              => $this->book->id,
                'title'           => $this->book->title,
                'slug'            => $this->book->slug,
                'cover_thumbnail' => $this->book->thumbnail_url ?? null,
                'author'          => $this->book->relationLoaded('author')
                    ? ['id' => $this->book->author?->id, 'name' => $this->book->author?->name]
                    : null,
            ]),

            'audiobook' => $this->whenLoaded('audioBook', fn () => [
                'id'    => $this->audioBook->id,
                'title' => $this->audioBook->title,
                'slug'  => $this->audioBook->slug,
            ]),
        ];
    }
}
