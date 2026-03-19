<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->enum('file_type', ['pdf', 'epub', 'mobi', 'djvu', 'fb2', 'txt'])->default('pdf');
            $table->string('s3_key', 1000);
            $table->string('s3_bucket');
            $table->string('original_name', 500);
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 100);
            $table->char('checksum_md5', 32)->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->enum('processing_status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->text('processing_error')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('book_id');
            $table->index('file_type');
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_files');
    }
};
