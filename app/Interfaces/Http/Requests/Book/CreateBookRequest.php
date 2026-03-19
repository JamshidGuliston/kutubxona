<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests\Book;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class CreateBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return $user->hasAnyRole(['tenant_admin', 'tenant_manager', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'title'           => ['required', 'string', 'max:500'],
            'language'        => ['required', 'string', 'in:uz,ru,en,fr,de,ar,tr'],
            'author_id'       => ['nullable', 'integer', 'exists:authors,id'],
            'publisher_id'    => ['nullable', 'integer', 'exists:publishers,id'],
            'category_id'     => ['nullable', 'integer', 'exists:categories,id'],
            'subtitle'        => ['nullable', 'string', 'max:500'],
            'description'     => ['nullable', 'string', 'max:50000'],
            'isbn'            => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-X]+$/'],
            'isbn13'          => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            'published_year'  => ['nullable', 'integer', 'min:1000', 'max:2099'],
            'edition'         => ['nullable', 'string', 'max:50'],
            'pages'           => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_featured'     => ['nullable', 'boolean'],
            'is_downloadable' => ['nullable', 'boolean'],
            'is_free'         => ['nullable', 'boolean'],
            'price'           => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'tag_ids'         => ['nullable', 'array', 'max:20'],
            'tag_ids.*'       => ['integer', 'exists:tags,id'],
            'category_ids'    => ['nullable', 'array', 'max:10'],
            'category_ids.*'  => ['integer', 'exists:categories,id'],
            'book_file'       => [
                'nullable',
                'file',
                'max:102400', // 100MB
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null) return;

                    $allowedMimes = [
                        'application/pdf',
                        'application/epub+zip',
                        'application/x-mobipocket-ebook',
                        'image/vnd.djvu',
                        'text/plain',
                    ];

                    $detectedMime = $value->getMimeType();
                    if (!in_array($detectedMime, $allowedMimes, true)) {
                        $fail("Invalid file type '{$detectedMime}'. Allowed: PDF, EPUB, MOBI, DJVU.");
                        return;
                    }

                    $allowedExtensions = ['pdf', 'epub', 'mobi', 'djvu', 'fb2', 'txt'];
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, $allowedExtensions, true)) {
                        $fail("Invalid file extension '.{$extension}'.");
                    }
                },
            ],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'    => 'Book title is required.',
            'language.required' => 'Language is required.',
            'language.in'       => 'Selected language is not supported.',
            'isbn.regex'        => 'ISBN must contain only digits, hyphens, and X.',
            'book_file.max'     => 'Book file must not exceed 100MB.',
            'cover_image.max'   => 'Cover image must not exceed 5MB.',
        ];
    }

    public function attributes(): array
    {
        return [
            'author_id'    => 'author',
            'publisher_id' => 'publisher',
            'category_id'  => 'category',
            'book_file'    => 'book file',
            'cover_image'  => 'cover image',
        ];
    }
}
