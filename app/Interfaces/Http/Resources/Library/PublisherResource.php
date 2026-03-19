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
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'country'     => $this->country,
            'founded'     => $this->founded,
            'website'     => $this->website,
            'logo'        => $this->logo_url ?? null,
            'books_count' => $this->books_count ?? null,
        ];
    }
}
