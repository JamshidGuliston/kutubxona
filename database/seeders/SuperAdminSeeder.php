<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Create a system tenant for super admin (tenant_id = 1)
        $systemTenant = \App\Domain\Tenant\Models\Tenant::withoutGlobalScopes()->firstOrCreate(
            ['slug' => 'system'],
            [
                'name'          => 'System',
                'slug'          => 'system',
                'status'        => \App\Domain\Tenant\Enums\TenantStatus::Active,
                'storage_quota' => 9_999_999_999_999, // ~10 TB (bigint safe)
                'max_users'     => 999_999_999,      // ~1B (unsigned int safe)
                'max_books'     => 999_999_999,      // ~1B (unsigned int safe)
                'settings'      => [],
            ]
        );

        app()->instance('tenant', $systemTenant);
        setPermissionsTeamId($systemTenant->id);

        $superAdmin = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'admin@kutubxona.uz')],
            [
                'tenant_id'         => $systemTenant->id,
                'name'              => env('SUPER_ADMIN_NAME', 'Super Admin'),
                'email'             => env('SUPER_ADMIN_EMAIL', 'admin@kutubxona.uz'),
                'password'          => Hash::make(env('SUPER_ADMIN_PASSWORD', 'Admin@123456!')),
                'email_verified_at' => now(),
                'status'            => 'active',
                'locale'            => 'uz',
            ]
        );

        $superAdmin->assignRole(\Spatie\Permission\Models\Role::findByName('super_admin', 'api'));

        $this->command->info("Super admin created: {$superAdmin->email}");
    }
}
