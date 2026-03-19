<?php

declare(strict_types=1);

namespace App\Application\DTOs\Book;

use App\Interfaces\Http\Requests\Book\CreateBookRequest;
use Illuminate\Http\UploadedFile;

final readonly class CreateBookDTO
{
    public function __construct(
        public string $title,
        public string $language,
        public ?int $authorId,
        public ?int $publisherId,
        public ?int $categoryId,
        public ?string $subtitle,
        public ?string $description,
        public ?string $isbn,
        public ?string $isbn13,
        public ?int $publishedYear,
        public ?string $edition,
        public ?int $pages,
        public bool $isFeatured,
        public bool $isDownloadable,
        public bool $isFree,
        public ?float $price,
        public array $tagIds,
        public array $categoryIds,
        public ?UploadedFile $bookFile,
        public ?UploadedFile $coverImage,
    ) {}

    public static function fromRequest(CreateBookRequest $request): self
    {
        return new self(
            title:          $request->validated('title'),
            language:       $request->validated('language', 'uz'),
            authorId:       $request->validated('author_id'),
            publisherId:    $request->validated('publisher_id'),
            categoryId:     $request->validated('category_id'),
            subtitle:       $request->validated('subtitle'),
            description:    $request->validated('description'),
            isbn:           $request->validated('isbn'),
            isbn13:         $request->validated('isbn13'),
            publishedYear:  $request->validated('published_year'),
            edition:        $request->validated('edition'),
            pages:          $request->validated('pages'),
            isFeatured:     (bool) $request->validated('is_featured', false),
            isDownloadable: (bool) $request->validated('is_downloadable', true),
            isFree:         (bool) $request->validated('is_free', true),
            price:          $request->validated('price'),
            tagIds:         $request->validated('tag_ids', []),
            categoryIds:    $request->validated('category_ids', []),
            bookFile:       $request->file('book_file'),
            coverImage:     $request->file('cover_image'),
        );
    }

    public function toArray(): array
    {
        return [
            'title'           => $this->title,
            'language'        => $this->language,
            'author_id'       => $this->authorId,
            'publisher_id'    => $this->publisherId,
            'category_id'     => $this->categoryId,
            'subtitle'        => $this->subtitle,
            'description'     => $this->description,
            'isbn'            => $this->isbn,
            'isbn13'          => $this->isbn13,
            'published_year'  => $this->publishedYear,
            'edition'         => $this->edition,
            'pages'           => $this->pages,
            'is_featured'     => $this->isFeatured,
            'is_downloadable' => $this->isDownloadable,
            'is_free'         => $this->isFree,
            'price'           => $this->price,
            'status'          => $this->bookFile ? 'processing' : 'draft',
        ];
    }
}
