<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->backfillBooks();
            $this->backfillAuthors();
            $this->backfillCategories();
            $this->backfillPublishers();
            $this->backfillTags();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('book_translations')->delete();
            DB::table('author_translations')->delete();
            DB::table('category_translations')->delete();
            DB::table('publisher_translations')->delete();
            DB::table('tag_translations')->delete();
        });
    }

    private function backfillBooks(): void
    {
        DB::table('books')->orderBy('id')->chunkById(500, function ($books): void {
            $rows = [];
            foreach ($books as $book) {
                // Skip if a translation row already exists for this book + locale
                $locale = $book->language ?: 'uz';
                $exists = DB::table('book_translations')
                    ->where('book_id', $book->id)
                    ->where('locale', $locale)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $rows[] = [
                    'tenant_id'   => $book->tenant_id,
                    'book_id'     => $book->id,
                    'locale'      => $locale,
                    'title'       => $book->title ?? '',
                    'subtitle'    => $book->subtitle,
                    'description' => $book->description,
                    'slug'        => $book->slug ?? '',
                    'created_at'  => $book->created_at ?? now(),
                    'updated_at'  => $book->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('book_translations')->insert($rows);
            }
        });
    }

    private function backfillAuthors(): void
    {
        DB::table('authors')->orderBy('id')->chunkById(500, function ($authors): void {
            $rows = [];
            foreach ($authors as $author) {
                $exists = DB::table('author_translations')
                    ->where('author_id', $author->id)
                    ->where('locale', 'uz')
                    ->exists();
                if ($exists) {
                    continue;
                }
                $rows[] = [
                    'tenant_id'  => $author->tenant_id,
                    'author_id'  => $author->id,
                    'locale'     => 'uz',
                    'name'       => $author->name ?? '',
                    'bio'        => $author->bio,
                    'slug'       => $author->slug ?? '',
                    'created_at' => $author->created_at ?? now(),
                    'updated_at' => $author->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('author_translations')->insert($rows);
            }
        });
    }

    private function backfillCategories(): void
    {
        DB::table('categories')->orderBy('id')->chunkById(500, function ($categories): void {
            $rows = [];
            foreach ($categories as $category) {
                $exists = DB::table('category_translations')
                    ->where('category_id', $category->id)
                    ->where('locale', 'uz')
                    ->exists();
                if ($exists) {
                    continue;
                }
                $rows[] = [
                    'tenant_id'   => $category->tenant_id,
                    'category_id' => $category->id,
                    'locale'      => 'uz',
                    'name'        => $category->name ?? '',
                    'description' => $category->description,
                    'slug'        => $category->slug ?? '',
                    'created_at'  => $category->created_at ?? now(),
                    'updated_at'  => $category->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('category_translations')->insert($rows);
            }
        });
    }

    private function backfillPublishers(): void
    {
        DB::table('publishers')->orderBy('id')->chunkById(500, function ($publishers): void {
            $rows = [];
            foreach ($publishers as $publisher) {
                $exists = DB::table('publisher_translations')
                    ->where('publisher_id', $publisher->id)
                    ->where('locale', 'uz')
                    ->exists();
                if ($exists) {
                    continue;
                }
                $rows[] = [
                    'tenant_id'    => $publisher->tenant_id,
                    'publisher_id' => $publisher->id,
                    'locale'       => 'uz',
                    'name'         => $publisher->name ?? '',
                    'description'  => $publisher->description,
                    'slug'         => $publisher->slug ?? '',
                    'created_at'   => $publisher->created_at ?? now(),
                    'updated_at'   => $publisher->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('publisher_translations')->insert($rows);
            }
        });
    }

    private function backfillTags(): void
    {
        DB::table('tags')->orderBy('id')->chunkById(500, function ($tags): void {
            $rows = [];
            foreach ($tags as $tag) {
                $exists = DB::table('tag_translations')
                    ->where('tag_id', $tag->id)
                    ->where('locale', 'uz')
                    ->exists();
                if ($exists) {
                    continue;
                }
                $rows[] = [
                    'tenant_id'  => $tag->tenant_id,
                    'tag_id'     => $tag->id,
                    'locale'     => 'uz',
                    'name'       => $tag->name ?? '',
                    'slug'       => $tag->slug ?? '',
                    'created_at' => $tag->created_at ?? now(),
                    'updated_at' => $tag->updated_at ?? now(),
                ];
            }
            if (! empty($rows)) {
                DB::table('tag_translations')->insert($rows);
            }
        });
    }
};
