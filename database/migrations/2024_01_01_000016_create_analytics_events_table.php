<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Nullable: anonymous users also generate events (page views, searches)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('event_type', [
                'page_view',
                'book_view',
                'book_read',
                'book_download',
                'search',
                'login',
                'register',
            ]);

            // Polymorphic reference: e.g., entity_type='book', entity_id=42
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            // Arbitrary event payload (search query, device info, filters used, etc.)
            $table->json('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();   // IPv6-safe
            $table->text('user_agent')->nullable();

            // Only created_at — analytics rows are immutable
            $table->timestamp('created_at')->useCurrent()->index();

            // Indexes for common query patterns
            $table->index('tenant_id');
            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
