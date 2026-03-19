<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('avatar', 500)->nullable();
            $table->enum('status', ['active', 'inactive', 'banned'])->default('active');
            $table->string('locale', 10)->default('uz');
            $table->json('preferences')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->string('email_verification_token', 64)->nullable();
            $table->string('password_reset_token', 64)->nullable();
            $table->timestamp('password_reset_expires')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->json('two_factor_recovery')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Email must be unique per tenant
            $table->unique(['tenant_id', 'email']);
            $table->index('tenant_id');
            $table->index('status');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
