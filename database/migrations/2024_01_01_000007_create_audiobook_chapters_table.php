<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobook_chapters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('audiobook_id')->constrained('audiobooks')->cascadeOnDelete();
            $table->string('title', 500);
            $table->unsignedSmallInteger('chapter_number');
            $table->string('s3_key', 1000);
            $table->string('s3_bucket');
            $table->unsignedInteger('duration')->nullable(); // seconds
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->default('audio/mpeg');
            $table->json('waveform_data')->nullable();
            $table->enum('processing_status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('audiobook_id');
            $table->index(['audiobook_id', 'chapter_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_chapters');
    }
};
