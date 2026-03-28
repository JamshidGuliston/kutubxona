<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->nullable();
            $table->unsignedInteger('max_users')->default(10);
            $table->unsignedInteger('max_books')->default(100);
            $table->unsignedInteger('storage_quota')->default(1024); // MB da (1GB = 1024MB)
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed default plans
        DB::table('plans')->insert([
            [
                'name'          => 'Free',
                'slug'          => 'free',
                'price_monthly' => 0,
                'price_yearly'  => 0,
                'max_users'     => 5,
                'max_books'     => 20,
                'storage_quota' => 512, // 512MB
                'features'      => json_encode([
                    'audiobooks'  => false,
                    'reviews'     => true,
                    'downloads'   => false,
                    'analytics'   => false,
                    'custom_domain'=> false,
                ]),
                'is_active'     => true,
                'sort_order'    => 0,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'name'          => 'Starter',
                'slug'          => 'starter',
                'price_monthly' => 29,
                'price_yearly'  => 290,
                'max_users'     => 50,
                'max_books'     => 500,
                'storage_quota' => 10240, // 10GB
                'features'      => json_encode([
                    'audiobooks'   => true,
                    'reviews'      => true,
                    'downloads'    => true,
                    'analytics'    => true,
                    'custom_domain'=> false,
                ]),
                'is_active'     => true,
                'sort_order'    => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'name'          => 'Professional',
                'slug'          => 'professional',
                'price_monthly' => 99,
                'price_yearly'  => 990,
                'max_users'     => 500,
                'max_books'     => 5000,
                'storage_quota' => 102400, // 100GB
                'features'      => json_encode([
                    'audiobooks'   => true,
                    'reviews'      => true,
                    'downloads'    => true,
                    'analytics'    => true,
                    'custom_domain'=> true,
                    'dedicated_db' => false,
                    'api_access'   => true,
                ]),
                'is_active'     => true,
                'sort_order'    => 2,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'name'          => 'Enterprise',
                'slug'          => 'enterprise',
                'price_monthly' => 499,
                'price_yearly'  => 4990,
                'max_users'     => 99999,
                'max_books'     => 99999,
                'storage_quota' => 1048576, // 1TB
                'features'      => json_encode([
                    'audiobooks'   => true,
                    'reviews'      => true,
                    'downloads'    => true,
                    'analytics'    => true,
                    'custom_domain'=> true,
                    'dedicated_db' => true,
                    'api_access'   => true,
                    'sso'          => true,
                    'priority_support' => true,
                ]),
                'is_active'     => true,
                'sort_order'    => 3,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
