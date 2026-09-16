<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

/**
 * Makes sure the roles and permissions the application requires exist.
 *
 * These are structure, not sample data: without them the first-run installer
 * cannot assign the admin role and registration cannot assign the default one.
 * Nothing ran the seeder on a fresh install, so a brand new instance crashed at
 * the last step of setup with "There is no role named `admin`".
 *
 * Idempotent by construction -- the seeder uses findOrCreate and
 * syncPermissions -- so it is safe on every boot, and an upgrade that adds a
 * permission picks it up automatically.
 */
class EnsureRoles extends Command
{
    protected $signature = 'doccum:ensure-roles';

    protected $description = 'Create any missing roles and permissions';

    public function handle(): int
    {
        $this->callSilent('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);

        $this->info('Roles and permissions are present.');

        return self::SUCCESS;
    }
}
