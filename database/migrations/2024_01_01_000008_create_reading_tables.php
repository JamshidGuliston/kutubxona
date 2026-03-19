<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reading Progress
        Schema::create('reading_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->cascadeOnDelete();
            $table->foreignId('audiobook_id')->nullable()->constrained('audiobooks')->cascadeOnDelete();
            $table->foreignId('book_file_id')->nullable()->constrained('book_files')->nullOnDelete();
            $table->unsignedMediumInteger('current_page')->nullable();
            $table->string('current_cfi', 500)->nullable();
            $table->unsignedSmallInteger('current_chapter')->nullable();
            $table->unsignedInteger('current_position')->nullable();
            $table->unsignedMediumInteger('total_pages')->nullable();
            $table->decimal('percentage', 5, 2)->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('reading_time')->default(0); // seconds
            $table->timestamp('last_read_at')->nullable();
            $table->json('device_info')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'book_id'], 'uniq_reading_progress_book');
            $table->unique(['tenant_id', 'user_id', 'audiobook_id'], 'uniq_reading_progress_audio');
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('book_id');
            $table->index('last_read_at');
        });

        // Bookmarks
        Schema::create('bookmarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->unsignedMediumInteger('page')->nullable();
            $table->string('cfi', 500)->nullable();
            $table->string('title', 255)->nullable();
            $table->text('note')->nullable();
            $table->string('color', 20)->default('yellow');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['user_id', 'book_id']);
        });

        // Highlights
        Schema::create('highlights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->unsignedMediumInteger('page')->nullable();
            $table->string('cfi_start', 500)->nullable();
            $table->string('cfi_end', 500)->nullable();
            $table->text('selected_text');
            $table->text('note')->nullable();
            $table->enum('color', ['yellow', 'green', 'blue', 'pink', 'purple'])->default('yellow');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['user_id', 'book_id']);
        });

        // Favorites
        Schema::create('favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->cascadeOnDelete();
            $table->foreignId('audiobook_id')->nullable()->constrained('audiobooks')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['user_id', 'book_id'], 'uniq_favorite_book');
            $table->unique(['user_id', 'audiobook_id'], 'uniq_favorite_audio');
            $table->index('tenant_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('highlights');
        Schema::dropIfExists('bookmarks');
        Schema::dropIfExists('reading_progress');
    }
};
