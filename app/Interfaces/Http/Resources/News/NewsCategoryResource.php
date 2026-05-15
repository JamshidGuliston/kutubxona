<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\News;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'slug'        => $this->trans('slug'),
            'name'        => $this->trans('name'),
            'description' => $this->trans('description'),
            'icon'        => $this->icon,
            'color'       => $this->color,
            'news_count'  => $this->whenCounted('news'),
        ];
    }
}
