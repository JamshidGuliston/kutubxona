<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Library;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Library\Models\Publisher
 */
final class PublisherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->trans('name'),
            'slug'        => $this->trans('slug'),
            'description' => $this->trans('description'),
            'country'     => $this->country,
            'founded'     => $this->founded,
            'website'     => $this->website,
            'logo'        => $this->logo_url ?? null,
            'books_count' => $this->books_count ?? null,

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
