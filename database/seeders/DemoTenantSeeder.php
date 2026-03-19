<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Services\TenantService;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

final class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('Skipping DemoTenantSeeder in production.');
            return;
        }

        if (Tenant::where('slug', 'demo')->exists()) {
            $this->command->info('Demo tenant already exists, skipping.');
            return;
        }

        /** @var TenantService $tenantService */
        $tenantService = app(TenantService::class);

        $tenant = $tenantService->createTenant([
            'name'           => 'Demo Library',
            'slug'           => 'demo',
            'admin_name'     => 'Demo Admin',
            'admin_email'    => 'admin@demo.kutubxona.uz',
            'admin_password' => 'Demo@123456!',
            'settings'       => [
                'locale'   => 'uz',
                'theme'    => 'default',
                'features' => [
                    'audiobooks' => true,
                    'reviews'    => true,
                    'downloads'  => true,
                ],
            ],
        ]);

        $this->command->info("Demo tenant created: {$tenant->slug}");
        $this->command->info("Demo admin login: admin@demo.kutubxona.uz / Demo@123456!");
        $this->command->info("Demo URL: https://demo.kutubxona.uz");
    }
}
