<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Roles hierarchy:
     *   super_admin    → platform owner, all access
     *   tenant_admin   → library admin, full tenant access
     *   tenant_manager → content manager, limited admin
     *   user           → regular library reader
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Book permissions
            'books.view',
            'books.create',
            'books.update',
            'books.delete',
            'books.publish',
            'books.download',
            'books.stream',

            // Audiobook permissions
            'audiobooks.view',
            'audiobooks.create',
            'audiobooks.update',
            'audiobooks.delete',
            'audiobooks.stream',

            // Author/Category/Publisher permissions
            'authors.manage',
            'categories.manage',
            'publishers.manage',
            'tags.manage',

            // User management
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.manage-roles',

            // Review moderation
            'reviews.moderate',
            'reviews.delete',

            // Analytics
            'analytics.view',

            // Tenant management (super admin only)
            'tenants.view',
            'tenants.create',
            'tenants.update',
            'tenants.delete',
            'tenants.suspend',
            'tenants.activate',

            // Reading features
            'reading.bookmarks',
            'reading.highlights',
            'reading.notes',
            'reading.progress',
        ];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'api');
        }

        // Super Admin — all permissions
        $superAdmin = Role::findOrCreate('super_admin', 'api');
        $superAdmin->syncPermissions(Permission::all());

        // Tenant Admin — all tenant-level permissions
        $tenantAdmin = Role::findOrCreate('tenant_admin', 'api');
        $tenantAdmin->syncPermissions([
            'books.view', 'books.create', 'books.update', 'books.delete', 'books.publish',
            'books.download', 'books.stream',
            'audiobooks.view', 'audiobooks.create', 'audiobooks.update', 'audiobooks.delete', 'audiobooks.stream',
            'authors.manage', 'categories.manage', 'publishers.manage', 'tags.manage',
            'users.view', 'users.create', 'users.update', 'users.delete', 'users.manage-roles',
            'reviews.moderate', 'reviews.delete',
            'analytics.view',
            'reading.bookmarks', 'reading.highlights', 'reading.notes', 'reading.progress',
        ]);

        // Tenant Manager — content management, no user deletion
        $tenantManager = Role::findOrCreate('tenant_manager', 'api');
        $tenantManager->syncPermissions([
            'books.view', 'books.create', 'books.update', 'books.publish',
            'books.download', 'books.stream',
            'audiobooks.view', 'audiobooks.create', 'audiobooks.update', 'audiobooks.stream',
            'authors.manage', 'categories.manage', 'publishers.manage', 'tags.manage',
            'users.view',
            'reviews.moderate',
            'analytics.view',
            'reading.bookmarks', 'reading.highlights', 'reading.notes', 'reading.progress',
        ]);

        // Regular User — read and personal features only
        $user = Role::findOrCreate('user', 'api');
        $user->syncPermissions([
            'books.view', 'books.download', 'books.stream',
            'audiobooks.view', 'audiobooks.stream',
            'reading.bookmarks', 'reading.highlights', 'reading.notes', 'reading.progress',
        ]);

        $this->command->info('Roles and permissions seeded successfully.');
    }
}
