<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── tenant_subscriptions ──────────────────────────────────────────────────
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->foreignId('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();

            $table->enum('status', [
                'active',
                'cancelled',
                'expired',
                'trialing',
                'past_due',
            ])->default('trialing');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // External billing reference (e.g., Stripe subscription ID)
            $table->string('external_id', 255)->nullable();

            // Billing cycle: monthly / yearly
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');

            // Store snapshot of plan price at time of subscription
            $table->unsignedInteger('amount_paid')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
            $table->index('status');
            $table->index('ends_at');
            $table->index('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
