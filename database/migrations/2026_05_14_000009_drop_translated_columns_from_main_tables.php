<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            try { $table->dropUnique(['tenant_id', 'slug']); } catch (\Throwable) {}
            try { $table->dropIndex(['language']); } catch (\Throwable) {}
            try { $table->dropFullText(['title', 'description']); } catch (\Throwable) {}
            $table->dropColumn(['title', 'subtitle', 'description', 'slug', 'language']);
        });

        Schema::table('authors', function (Blueprint $table): void {
            try { $table->dropUnique(['tenant_id', 'slug']); } catch (\Throwable) {}
            try { $table->dropFullText(['name']); } catch (\Throwable) {}
            $table->dropColumn(['name', 'slug', 'bio']);
        });

        Schema::table('categories', function (Blueprint $table): void {
            try { $table->dropUnique(['tenant_id', 'slug']); } catch (\Throwable) {}
            $table->dropColumn(['name', 'slug', 'description']);
        });

        Schema::table('publishers', function (Blueprint $table): void {
            try { $table->dropUnique(['tenant_id', 'slug']); } catch (\Throwable) {}
            $table->dropColumn(['name', 'slug', 'description']);
        });

        Schema::table('tags', function (Blueprint $table): void {
            try { $table->dropUnique(['tenant_id', 'slug']); } catch (\Throwable) {}
            $table->dropColumn(['name', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->string('title', 500)->nullable();
            $table->string('subtitle', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('slug', 500)->nullable();
            $table->string('language', 10)->default('uz');
        });
        Schema::table('authors', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('bio')->nullable();
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
        });
        Schema::table('publishers', function (Blueprint $table): void {
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
        });
        Schema::table('tags', function (Blueprint $table): void {
            $table->string('name', 100)->nullable();
            $table->string('slug', 100)->nullable();
        });

        DB::transaction(function (): void {
            DB::statement("UPDATE books SET title = bt.title, subtitle = bt.subtitle, description = bt.description, slug = bt.slug, language = bt.locale FROM book_translations bt WHERE books.id = bt.book_id AND bt.locale = 'uz'");
            DB::statement("UPDATE authors SET name = at.name, bio = at.bio, slug = at.slug FROM author_translations at WHERE authors.id = at.author_id AND at.locale = 'uz'");
            DB::statement("UPDATE categories SET name = ct.name, description = ct.description, slug = ct.slug FROM category_translations ct WHERE categories.id = ct.category_id AND ct.locale = 'uz'");
            DB::statement("UPDATE publishers SET name = pt.name, description = pt.description, slug = pt.slug FROM publisher_translations pt WHERE publishers.id = pt.publisher_id AND pt.locale = 'uz'");
            DB::statement("UPDATE tags SET name = tt.name, slug = tt.slug FROM tag_translations tt WHERE tags.id = tt.tag_id AND tt.locale = 'uz'");
        });
    }
};
