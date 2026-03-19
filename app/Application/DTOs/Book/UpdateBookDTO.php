<?php

declare(strict_types=1);

namespace App\Application\DTOs\Book;

use Illuminate\Http\UploadedFile;

final readonly class UpdateBookDTO
{
    public function __construct(
        public ?string $title,
        public ?string $language,
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
        public ?bool $isFeatured,
        public ?bool $isDownloadable,
        public ?bool $isFree,
        public ?float $price,
        public ?string $status,
        public ?array $tagIds,
        public ?array $categoryIds,
        public ?UploadedFile $bookFile,
        public ?UploadedFile $coverImage,
    ) {}

    public static function fromArray(array $data, ?UploadedFile $bookFile = null, ?UploadedFile $coverImage = null): self
    {
        return new self(
            title:          $data['title'] ?? null,
            language:       $data['language'] ?? null,
            authorId:       $data['author_id'] ?? null,
            publisherId:    $data['publisher_id'] ?? null,
            categoryId:     $data['category_id'] ?? null,
            subtitle:       $data['subtitle'] ?? null,
            description:    $data['description'] ?? null,
            isbn:           $data['isbn'] ?? null,
            isbn13:         $data['isbn13'] ?? null,
            publishedYear:  isset($data['published_year']) ? (int) $data['published_year'] : null,
            edition:        $data['edition'] ?? null,
            pages:          isset($data['pages']) ? (int) $data['pages'] : null,
            isFeatured:     isset($data['is_featured']) ? (bool) $data['is_featured'] : null,
            isDownloadable: isset($data['is_downloadable']) ? (bool) $data['is_downloadable'] : null,
            isFree:         isset($data['is_free']) ? (bool) $data['is_free'] : null,
            price:          isset($data['price']) ? (float) $data['price'] : null,
            status:         $data['status'] ?? null,
            tagIds:         $data['tag_ids'] ?? null,
            categoryIds:    $data['category_ids'] ?? null,
            bookFile:       $bookFile,
            coverImage:     $coverImage,
        );
    }

    /**
     * Only include non-null fields for partial update.
     */
    public function toArray(): array
    {
        return array_filter([
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
            'status'          => $this->status,
        ], fn ($v) => $v !== null);
    }
}
