<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\AudioBook;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\AudioBook\Models\AudioBook
 */
final class AudioBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'slug'             => $this->slug,
            'description'      => $this->description,
            'language'         => $this->language,
            'cover_image'      => $this->cover_url ?? null,
            'status'           => $this->status,
            'duration_seconds' => $this->duration_seconds,
            'duration_human'   => $this->duration_human ?? null,
            'is_free'          => $this->is_free,
            'price'            => $this->price,
            'average_rating'   => $this->average_rating ? (float) $this->average_rating : null,
            'rating_count'     => $this->rating_count,
            'play_count'       => $this->play_count ?? 0,

            'author' => $this->whenLoaded('author', fn () => [
                'id'   => $this->author->id,
                'name' => $this->author->name,
                'slug' => $this->author->slug,
            ]),

            'category' => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),

            'chapters' => $this->whenLoaded('chapters', fn () =>
                $this->chapters->map(fn ($ch) => [
                    'id'               => $ch->id,
                    'title'            => $ch->title,
                    'chapter_number'   => $ch->chapter_number,
                    'duration_seconds' => $ch->duration_seconds,
                    'sort_order'       => $ch->sort_order,
                ])
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
