<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Book;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Book\Models\Book
 */
final class BookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'slug'            => $this->slug,
            'subtitle'        => $this->subtitle,
            'description'     => $this->description,
            'isbn'            => $this->isbn,
            'isbn13'          => $this->isbn13,
            'language'        => $this->language,
            'published_year'  => $this->published_year,
            'edition'         => $this->edition,
            'pages'           => $this->pages,
            'cover_image'     => $this->cover_url,
            'cover_thumbnail' => $this->thumbnail_url,
            'cover_url'       => $this->cover_url,
            'thumbnail_url'   => $this->thumbnail_url,
            'status'          => $this->status,
            'is_featured'     => $this->is_featured,
            'is_downloadable' => $this->is_downloadable,
            'is_free'         => $this->is_free,
            'price'           => $this->price,
            'download_count'  => $this->download_count,
            'view_count'      => $this->view_count,
            'average_rating'  => $this->average_rating ? (float) $this->average_rating : null,
            'rating_count'    => $this->rating_count,

            // Relationships (eager loaded)
            'author' => $this->whenLoaded('author', fn () => [
                'id'   => $this->author->id,
                'name' => $this->author->name,
                'slug' => $this->author->slug,
            ]),

            'publisher' => $this->whenLoaded('publisher', fn () => [
                'id'   => $this->publisher->id,
                'name' => $this->publisher->name,
                'slug' => $this->publisher->slug,
            ]),

            'categories' => $this->whenLoaded('categories', fn () =>
                $this->categories->map(fn ($c) => [
                    'id'   => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                ])
            ),

            'tags' => $this->whenLoaded('tags', fn () =>
                $this->tags->map(fn ($t) => [
                    'id'    => $t->id,
                    'name'  => $t->name,
                    'slug'  => $t->slug,
                    'color' => $t->color,
                ])
            ),

            'files' => $this->whenLoaded('files', fn () =>
                $this->files->where('processing_status', 'ready')->map(fn ($f) => [
                    'id'         => $f->id,
                    'file_type'  => $f->file_type,
                    'file_size'  => $f->file_size,
                    'is_primary' => $f->is_primary,
                ])
            ),

            // Meta
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at'   => $this->created_at->toIso8601String(),
            'updated_at'   => $this->updated_at->toIso8601String(),

            // Additional data injected by controller (user-specific)
            $this->mergeWhen(isset($this->additional['user_progress']), [
                'user_progress' => $this->additional['user_progress'] ?? null,
            ]),
            $this->mergeWhen(isset($this->additional['is_favorited']), [
                'is_favorited' => $this->additional['is_favorited'] ?? false,
            ]),
        ];
    }
}
