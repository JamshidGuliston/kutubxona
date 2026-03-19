<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests\Book;

use App\Domain\Book\Enums\BookStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->hasRole(['tenant_admin', 'tenant_manager', 'super_admin']) ?? false;
    }

    public function rules(): array
    {
        return [
            'title'          => ['sometimes', 'string', 'max:500'],
            'subtitle'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'description'    => ['sometimes', 'nullable', 'string', 'max:10000'],
            'author_id'      => ['sometimes', 'nullable', 'integer', 'exists:authors,id'],
            'publisher_id'   => ['sometimes', 'nullable', 'integer', 'exists:publishers,id'],
            'category_id'    => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'category_ids'   => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'tag_ids'        => ['sometimes', 'array'],
            'tag_ids.*'      => ['integer', 'exists:tags,id'],
            'language'       => ['sometimes', 'string', 'size:2'],
            'published_year' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:' . date('Y')],
            'isbn'           => ['sometimes', 'nullable', 'string', 'max:20'],
            'isbn13'         => ['sometimes', 'nullable', 'string', 'size:13'],
            'pages'          => ['sometimes', 'nullable', 'integer', 'min:1'],
            'edition'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_featured'    => ['sometimes', 'boolean'],
            'is_downloadable'=> ['sometimes', 'boolean'],
            'is_free'        => ['sometimes', 'boolean'],
            'price'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status'         => ['sometimes', Rule::enum(BookStatus::class)],
            'book_file'      => ['sometimes', 'nullable', 'file', 'mimes:pdf,epub', 'max:102400'],
            'cover_image'    => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
