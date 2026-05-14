<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('publisher_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publisher_id')->constrained('publishers')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug');
            $table->timestamps();

            $table->unique(['publisher_id', 'locale']);
            $table->unique(['tenant_id', 'locale', 'slug']);
            $table->index(['tenant_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_translations');
    }
};
