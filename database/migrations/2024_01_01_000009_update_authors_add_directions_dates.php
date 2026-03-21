<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authors', function (Blueprint $table): void {
            // Replace year columns with full date columns
            $table->dropColumn(['birth_year', 'death_year']);
            $table->date('birth_date')->nullable()->after('nationality');
            $table->date('death_date')->nullable()->after('birth_date');

            // Directions: ['shoir', 'yozuvchi', 'dramaturg', ...]
            $table->json('directions')->nullable()->after('death_date');
        });
    }

    public function down(): void
    {
        Schema::table('authors', function (Blueprint $table): void {
            $table->dropColumn(['birth_date', 'death_date', 'directions']);
            $table->smallInteger('birth_year')->nullable();
            $table->smallInteger('death_year')->nullable();
        });
    }
};
