<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\News;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = auth()->id();
        $isLiked = $userId
            ? $this->likes->contains(fn ($l) => $l->user_id === $userId)
            : false;

        return [
            'id'             => $this->id,
            'slug'           => $this->trans('slug'),
            'title'          => $this->trans('title'),
            'excerpt'        => $this->trans('excerpt'),
            'body'           => $this->trans('body'),
            'cover_url'      => $this->cover_url,
            'thumbnail_url'  => $this->thumbnail_url,
            'category'       => $this->whenLoaded('category', fn () => $this->category ? [
                'id'   => $this->category->id,
                'slug' => $this->category->trans('slug'),
                'name' => $this->category->trans('name'),
            ] : null),
            'author'         => $this->whenLoaded('author', fn () => $this->author ? [
                'id'   => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'view_count'     => $this->view_count,
            'like_count'     => $this->like_count,
            'comment_count'  => $this->comment_count,
            'is_liked'       => $isLiked,
            'is_featured'    => $this->is_featured,
            'published_at'   => $this->published_at?->toIso8601String(),
            'meta_title'     => $this->trans('meta_title'),
            'meta_description' => $this->trans('meta_description'),
            'translations'   => $this->when(
                $request->boolean('with_translations'),
                fn () => $this->translations->keyBy('locale')->map(fn ($t) => [
                    'title'   => $t->title,
                    'slug'    => $t->slug,
                    'excerpt' => $t->excerpt,
                    'body'    => $t->body,
                ])
            ),
        ];
    }
}
