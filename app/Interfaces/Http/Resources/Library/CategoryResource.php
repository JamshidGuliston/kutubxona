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
            'name'        => $this->trans('name'),
            'slug'        => $this->trans('slug'),
            'description' => $this->trans('description'),
            'icon'        => $this->icon,
            'color'       => $this->color,
            'sort_order'  => $this->sort_order,
            'is_active'   => $this->is_active,
            'parent_id'   => $this->parent_id,
            'books_count' => $this->books_count ?? null,

            'parent' => $this->whenLoaded('parent', fn () => [
                'id'   => $this->parent->id,
                'name' => $this->parent->trans('name'),
                'slug' => $this->parent->trans('slug'),
            ]),

            'children' => $this->whenLoaded('children', fn () =>
                $this->children->map(fn ($child) => [
                    'id'   => $child->id,
                    'name' => $child->trans('name'),
                    'slug' => $child->trans('slug'),
                    'icon' => $child->icon,
                ])
            ),

            // Translations (opt-in via with_translations query param)
            'translations' => $this->when(
                $request->boolean('with_translations'),
                fn () => $this->translations->keyBy('locale')->map(fn ($t) => [
                    'name'        => $t->name,
                    'description' => $t->description,
                    'slug'        => $t->slug,
                ])
            ),
        ];
    }
}
