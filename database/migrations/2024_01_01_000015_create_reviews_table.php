<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('book_id')
                ->constrained('books')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');        // 1–5
            $table->string('title', 255)->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // One review per user per book per tenant
            $table->unique(['tenant_id', 'user_id', 'book_id']);
            $table->index('tenant_id');
            $table->index('book_id');
            $table->index('user_id');
            $table->index(['tenant_id', 'is_approved']);
            $table->index(['book_id', 'is_approved', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
