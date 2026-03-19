<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Library;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Library\Models\Category
 */
final class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'icon'        => $this->icon,
            'color'       => $this->color,
            'sort_order'  => $this->sort_order,
            'is_active'   => $this->is_active,
            'parent_id'   => $this->parent_id,
            'books_count' => $this->books_count ?? null,

            'parent' => $this->whenLoaded('parent', fn () => [
                'id'   => $this->parent->id,
                'name' => $this->parent->name,
                'slug' => $this->parent->slug,
            ]),

            'children' => $this->whenLoaded('children', fn () =>
                $this->children->map(fn ($child) => [
                    'id'   => $child->id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                    'icon' => $child->icon,
                ])
            ),
        ];
    }
}
