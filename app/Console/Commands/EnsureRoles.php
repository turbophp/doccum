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
 * This runs on every container boot (docker/entrypoint.d/51-doccum-roles.sh,
 * gated only by AUTORUN_ENABLED) against a database an operator may have
 * already edited through Settings -> Roles. "Ensure" therefore means CREATE
 * WHAT IS ABSENT, never assert what a role's permission set must be --
 * RolesAndPermissionsSeeder::run()'s docblock is the full statement of that
 * decision and its trade-off.
 *
 * A previous version of this docblock said the seeder's use of findOrCreate
 * and syncPermissions() made every boot "safe", and that an upgrade adding a
 * permission "picks it up automatically". That was backwards: syncPermissions()
 * REPLACES a role's permission set, so it was exactly why every restart
 * silently discarded an operator's edit (issue #214) -- idempotent against
 * the seeder's own constants, destructive against the database. The seeder
 * no longer calls it.
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
