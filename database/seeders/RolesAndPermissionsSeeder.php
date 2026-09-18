<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the roles and permissions the application needs -- and ONLY
 * creates. This seeder is what runs, via App\Console\Commands\EnsureRoles,
 * on every container boot (docker/entrypoint.d/51-doccum-roles.sh, gated by
 * AUTORUN_ENABLED), against a database an operator has had every chance to
 * edit through Settings -> Roles (App\Livewire\Admin\Roles,
 * App\Actions\Roles\SetRolePermissions). That is the deciding fact for what
 * "ensure" is allowed to mean here.
 *
 * The decision (issue #214): "ensure" means create what is absent, never
 * assert what a set must be. Concretely --
 *
 *  - Every name in PERMISSIONS gets a Permission row if it does not already
 *    have one (Permission::findOrCreate), on every run, fresh install or
 *    upgrade alike. This is what makes a permission introduced by an
 *    upgrade show up at all -- it becomes a row that Settings -> Roles can
 *    offer a checkbox for.
 *  - `admin` and `member` are each created if missing (Role::findOrCreate),
 *    and ONLY a role that this call just created (Role::$wasRecentlyCreated)
 *    is given the default permission set below. A role that already existed
 *    is left exactly as it was -- no syncPermissions(), no diffing, nothing
 *    that touches its role_has_permissions rows at all.
 *
 * What this buys: a fresh install still gets `admin` holding every
 * permission and `member` holding the day-to-day ones (both roles are new,
 * so both get the default set), and an operator's edit through Settings ->
 * Roles now survives every subsequent restart, because a restart only ever
 * re-runs this against roles that already exist.
 *
 * What this trades away: an upgrade that adds a new permission to PERMISSIONS
 * or MEMBER_PERMISSIONS is picked up as a Permission row (see above) but is
 * NOT retroactively granted to `admin` or `member` on an instance that
 * already has those roles -- there is deliberately no third state between
 * "role is new, give it everything" and "role exists, touch nothing". An
 * operator upgrading an existing instance grants a newly introduced
 * permission to whichever role should have it through Settings -> Roles
 * themselves, the same surface that let them take one away. That is a
 * one-time manual step per upgrade that adds a permission, in exchange for
 * every edit an operator makes surviving every restart -- previously the
 * seeder's syncPermissions() call did the opposite trade unconditionally,
 * which is issue #214.
 *
 * `admin` holding every permission is therefore NOT an invariant enforced
 * here after the role exists -- it is the default an operator starts from
 * and may deliberately change (App\Livewire\Admin\Roles lets `admin`'s own
 * row be edited exactly like `member`'s). The one thing that stays
 * enforced regardless is that SOME role or user keeps holding
 * `users.manage` -- that is App\Support\LastAdministrator's job, checked by
 * App\Actions\Roles\SetRolePermissions, and it is unrelated to this seeder.
 */
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

        $this->createRoleIfMissing('admin', self::PERMISSIONS);
        $this->createRoleIfMissing('member', self::MEMBER_PERMISSIONS);
    }

    /**
     * Creates $name if it does not exist yet, and ONLY then grants it
     * $defaultPermissions. A role that already exists is returned untouched
     * -- see the class docblock for why that half is load-bearing, not an
     * omission: giving a pre-existing role's permissions here is exactly the
     * syncPermissions() call issue #214 was filed against.
     *
     * Role::findOrCreate()->wasRecentlyCreated is Eloquent's own signal for
     * "this exact call created the row", set by performInsert() and never
     * set on a model that findOrCreate() instead loaded -- it is not this
     * class re-deriving "did I just create this", it is asking the ORM what
     * it did.
     *
     * @param  array<int, string>  $defaultPermissions
     */
    private function createRoleIfMissing(string $name, array $defaultPermissions): void
    {
        // MUTATION -- DO NOT MERGE. The destructive behaviour of issue #214
        // restored, so the new verify-phase smoke check has to prove it can
        // actually fail. An assertion nobody has seen fail is worth nothing
        // (decision/0067).
        Role::findOrCreate($name, 'web')->syncPermissions($defaultPermissions);
    }
}
