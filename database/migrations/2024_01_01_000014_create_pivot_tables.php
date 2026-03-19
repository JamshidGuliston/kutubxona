<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── book_author ──────────────────────────────────────────────────────────
        Schema::create('book_author', function (Blueprint $table): void {
            $table->foreignId('book_id')
                ->constrained('books')
                ->cascadeOnDelete();
            $table->foreignId('author_id')
                ->constrained('authors')
                ->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->primary(['book_id', 'author_id']);
            $table->index('author_id');
        });

        // ── book_category ────────────────────────────────────────────────────────
        // Note: books also have a primary category_id FK on the books table.
        // This pivot stores the multi-category relationship.
        Schema::create('book_category', function (Blueprint $table): void {
            $table->foreignId('book_id')
                ->constrained('books')
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['book_id', 'category_id']);
            $table->index('category_id');
        });

        // ── book_genre ───────────────────────────────────────────────────────────
        Schema::create('book_genre', function (Blueprint $table): void {
            $table->foreignId('book_id')
                ->constrained('books')
                ->cascadeOnDelete();
            $table->foreignId('genre_id')
                ->constrained('genres')
                ->cascadeOnDelete();

            $table->primary(['book_id', 'genre_id']);
            $table->index('genre_id');
        });

        // ── book_tag ─────────────────────────────────────────────────────────────
        Schema::create('book_tag', function (Blueprint $table): void {
            $table->foreignId('book_id')
                ->constrained('books')
                ->cascadeOnDelete();
            $table->foreignId('tag_id')
                ->constrained('tags')
                ->cascadeOnDelete();

            $table->primary(['book_id', 'tag_id']);
            $table->index('tag_id');
        });

        // ── user_favorite_books ──────────────────────────────────────────────────
        Schema::create('user_favorite_books', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('book_id')
                ->constrained('books')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'book_id']);
            $table->index('tenant_id');
            $table->index(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorite_books');
        Schema::dropIfExists('book_tag');
        Schema::dropIfExists('book_genre');
        Schema::dropIfExists('book_category');
        Schema::dropIfExists('book_author');
    }
};
