<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained('publishers')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('title', 500);
            $table->string('slug', 500);
            $table->text('description')->nullable();
            $table->string('narrator', 255)->nullable();
            $table->string('language', 10)->default('uz');
            $table->year('published_year')->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->string('cover_thumbnail', 500)->nullable();
            $table->unsignedInteger('total_duration')->nullable(); // seconds
            $table->unsignedTinyInteger('total_chapters')->default(0);
            $table->enum('status', ['draft', 'published', 'archived', 'processing'])->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_free')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('listen_count')->default(0);
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
            $table->index('author_id');
            $table->index('status');
            $table->fullText(['title', 'description']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobooks');
    }
};
