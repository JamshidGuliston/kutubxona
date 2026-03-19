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
            'name'        => $this->name,
            'slug'        => $this->slug,
            'bio'         => $this->bio,
            'nationality' => $this->nationality,
            'birth_year'  => $this->birth_year,
            'death_year'  => $this->death_year,
            'photo'       => $this->photo_url ?? null,
            'website'     => $this->website,
            'books_count' => $this->books_count ?? null,
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
