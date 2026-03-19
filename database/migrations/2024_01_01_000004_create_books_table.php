<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('bio')->nullable();
            $table->string('photo', 500)->nullable();
            $table->smallInteger('birth_year')->nullable();
            $table->smallInteger('death_year')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('website', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
            $table->fullText('name');
        });

        Schema::create('publishers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('country', 100)->nullable();
            $table->smallInteger('founded_year')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('icon', 255)->nullable();
            $table->char('color', 7)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lft')->nullable();
            $table->unsignedInteger('rgt')->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
            $table->index('parent_id');
            $table->index(['lft', 'rgt']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->char('color', 7)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
        });

        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
            $table->foreignId('publisher_id')->nullable()->constrained('publishers')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('title', 500);
            $table->string('slug', 500);
            $table->string('subtitle', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('isbn', 20)->nullable();
            $table->string('isbn13', 20)->nullable();
            $table->string('language', 10)->default('uz');
            $table->year('published_year')->nullable();
            $table->string('edition', 50)->nullable();
            $table->mediumInteger('pages')->unsigned()->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->string('cover_thumbnail', 500)->nullable();
            $table->enum('status', ['draft', 'published', 'archived', 'processing'])->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_downloadable')->default(true);
            $table->boolean('is_free')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index('tenant_id');
            $table->index('author_id');
            $table->index('publisher_id');
            $table->index('category_id');
            $table->index('status');
            $table->index('language');
            $table->index('published_year');
            $table->index('is_featured');
            $table->index('created_at');
            $table->index('download_count');
            $table->fullText(['title', 'description']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('books');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('publishers');
        Schema::dropIfExists('authors');
    }
};
