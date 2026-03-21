<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->string('pdf_path', 1000)->nullable()->after('cover_thumbnail');
            $table->string('audio_path', 1000)->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropColumn(['pdf_path', 'audio_path']);
        });
    }
};
