<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Library;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Library\Models\Author
 */
final class AuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->trans('name'),
            'slug'        => $this->trans('slug'),
            'bio'         => $this->trans('bio'),
            'nationality' => $this->nationality,
            'birth_year'  => $this->birth_year,
            'death_year'  => $this->death_year,
            'photo'       => $this->photo_url ?? null,
            'website'     => $this->website,
            'books_count' => $this->books_count ?? null,
            'created_at'  => $this->created_at?->toIso8601String(),

            // Translations (opt-in via with_translations query param)
            'translations' => $this->when(
                $request->boolean('with_translations'),
                fn () => $this->translations->keyBy('locale')->map(fn ($t) => [
                    'name' => $t->name,
                    'bio'  => $t->bio,
                    'slug' => $t->slug,
                ])
            ),
        ];
    }
}
