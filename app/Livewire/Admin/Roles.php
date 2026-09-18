<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Roles\SetRolePermissions;
use App\Exceptions\LastAdministratorMustRemain;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * A roles x permissions matrix, one checkbox per cell, one Save per role.
 * See spec §10 and item/admin-roles (issue #19).
 *
 * Gated on `users.manage`, deliberately NOT a new `roles.manage`
 * permission: RolesAndPermissionsSeeder::PERMISSIONS has no such entry, and
 * adding one would mean a migration and a seeding story this item should
 * not carry. More to the point, roles and users are one administrative
 * concern here -- spec §10 lists "users and role assignment" and "roles and
 * permissions" as sibling Settings sections, not independent ones, and this
 * page is reached from the SAME Settings entry point as
 * App\Livewire\Admin\Users (see resources/views/layouts/app/topbar.blade.php).
 * A viewer who may create and re-role users may also decide what those
 * roles can do.
 *
 * Every ability check here is deliberately doubled up with routes/web.php's
 * own `can:users.manage` middleware on the `admin.roles` route, the same
 * reason App\Livewire\Admin\Users doubles its checks (see that class's own
 * docblock): a Livewire method call reaches /livewire/update directly and
 * does not re-run route middleware bound to a DIFFERENT route than the one
 * that rendered the page.
 */
#[Layout('layouts::app')]
class Roles extends Component
{
    /**
     * Pending checkbox state per role id, then per permission name:
     * $permissionChoice[$roleId][$permissionName] === true means "checked".
     * Seeded lazily in render() from each role's current permissions,
     * mirroring App\Livewire\Admin\Users::$roleChoice.
     *
     * The key is int|string, not int, because Spatie's Role model supports a
     * uuid primary key as well as an auto-incrementing one -- $role->id is
     * genuinely either. This app's own migration uses bigIncrements, so a
     * (int) cast would be correct TODAY and would be asserting something the
     * package does not promise; PHP coerces integer-like string keys to int
     * on write regardless, so the wider declaration is the true one.
     *
     * @var array<int|string, array<string, bool>>
     */
    public array $permissionChoice = [];

    public function mount(): void
    {
        $this->authorize('users.manage');
    }

    /**
     * Applies the pending checkbox state for one role, refusing (readably)
     * a change that would leave `users.manage` with no holder at all.
     */
    public function saveRole(int $roleId): void
    {
        // find() + abort_if, not findOrFail() -- App\Livewire\Admin\Users::
        // changeRole() gives the same reasoning: findOrFail() throws
        // ModelNotFoundException, which a real request renders as a 404 but
        // which a Livewire component test never sees as one -- it propagates
        // as the raw exception instead of something assertNotFound() can
        // catch.
        $role = Role::query()->find($roleId);

        abort_if($role === null, 404);

        // Method-level, alongside mount()'s -- see the class docblock.
        $this->authorize('users.manage');

        $permissions = array_keys(array_filter($this->permissionChoice[$roleId] ?? []));

        try {
            app(SetRolePermissions::class)->handle($role, $permissions);
        } catch (LastAdministratorMustRemain $e) {
            // Surfaced through the error bag rather than left to escape as
            // a 500 -- see the class docblock and .github/mutations.json.
            // A fixed key, not one keyed by role id, for the same reason
            // Users::changeRole() uses a fixed key: the operator reads one
            // element regardless of which row triggered it.
            $this->addError('lastAdministrator', $e->getMessage());

            // Snap the pending checkboxes back to what is actually stored,
            // so the matrix does not keep showing a change that was refused.
            $role->load('permissions');
            $this->permissionChoice[$roleId] = $this->choicesFor($role);

            return;
        }

        $this->resetErrorBag('lastAdministrator');
    }

    public function render(): View
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();

        foreach ($roles as $role) {
            if (! array_key_exists($role->id, $this->permissionChoice)) {
                $this->permissionChoice[$role->id] = $this->choicesFor($role);
            }
        }

        return view('livewire.admin.roles', [
            'roles' => $roles,
            'permissions' => RolesAndPermissionsSeeder::PERMISSIONS,
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function choicesFor(Role $role): array
    {
        $held = $role->permissions->pluck('name')->all();

        $choices = [];

        foreach (RolesAndPermissionsSeeder::PERMISSIONS as $name) {
            $choices[$name] = in_array($name, $held, true);
        }

        return $choices;
    }
}
