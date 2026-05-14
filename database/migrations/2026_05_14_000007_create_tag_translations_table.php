<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tag_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->timestamps();

            $table->unique(['tag_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_translations');
    }
};
