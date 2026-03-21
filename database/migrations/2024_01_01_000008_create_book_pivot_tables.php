<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_categories', function (Blueprint $table): void {
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['book_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('book_tags', function (Blueprint $table): void {
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->primary(['book_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_tags');
        Schema::dropIfExists('book_categories');
    }
};
