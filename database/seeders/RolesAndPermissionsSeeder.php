<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Capabilities answer "what may this person ever do"; directory_access
     * answers "where". Both must pass. See spec §5.
     */
    public const PERMISSIONS = [
        'files.upload',
        'files.delete',
        'files.restore',
        'directories.create',
        'directories.manage',
        'properties.manage',
        'users.manage',
        'periods.manage',
        // The admin bypass of per-directory access.
        'directories.view-all',
    ];

    public const MEMBER_PERMISSIONS = [
        'files.upload',
        'files.delete',
        'files.restore',
        'directories.create',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('admin', 'web')->syncPermissions(self::PERMISSIONS);
        Role::findOrCreate('member', 'web')->syncPermissions(self::MEMBER_PERMISSIONS);
    }
}
