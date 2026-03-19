<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->enum('status', ['active', 'suspended', 'pending', 'cancelled'])
                  ->default('pending');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('storage_quota')->default(10737418240); // 10 GB
            $table->unsignedBigInteger('storage_used')->default(0);
            $table->unsignedInteger('max_users')->default(100);
            $table->unsignedInteger('max_books')->default(1000);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
