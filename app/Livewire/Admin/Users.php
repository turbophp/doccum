<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Users\CreateUser;
use App\Actions\Users\SetUserRoles;
use App\Exceptions\LastAdministratorMustRemain;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * User CRUD and role assignment, gated entirely by the `users.manage`
 * permission -- modelled on App\Livewire\Admin\PropertyDefinitions. See spec
 * §10 and issue #18.
 *
 * Every ability check here is deliberately doubled up with
 * App\Providers\DoccumServiceProvider's route middleware
 * (`can:users.manage` on admin.users): CLAUDE.md's two-layer authorisation
 * rule is about directory_access vs Spatie permission, but the SAME "no
 * single check is trusted alone" instinct applies to route middleware vs a
 * Livewire component's own mount()/method-level authorize() calls, since a
 * Livewire method call reaches /livewire/update directly and does not
 * re-run route middleware bound to a DIFFERENT route.
 */
#[Layout('layouts::app')]
class Users extends Component
{
    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = '';

    /**
     * Pending role choice per user id, keyed by User::$id. Seeded lazily in
     * render() from each user's current role rather than in mount(), so a
     * user created later in the same page session (through save() below)
     * gets an entry too without a second pass.
     *
     * @var array<int, string>
     */
    public array $roleChoice = [];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function save(): void
    {
        $this->authorize('create', User::class);

        // CreateUser validates and throws Illuminate\Validation\
        // ValidationException on failure -- Livewire's own
        // SupportValidation ComponentHook catches ANY ValidationException
        // raised during a component method call and turns it into this
        // component's error bag, exactly as $this->validate() does, so a
        // duplicate email/username surfaces as a normal validation error
        // here too, not a 500. See App\Actions\Users\CreateUser.
        app(CreateUser::class)->handle([
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ], $this->role !== '' ? $this->role : null);

        $this->reset(['name', 'username', 'email', 'password', 'password_confirmation', 'role']);
    }

    /**
     * Applies the pending role choice for one user, refusing (readably) a
     * change that would leave `users.manage` with no holder at all.
     */
    public function changeRole(int $userId): void
    {
        // find() + abort_if, not findOrFail() -- the same reasoning
        // App\Livewire\Files\Browser::revokeAccess() gives for the identical
        // choice: findOrFail() raises ModelNotFoundException, which a real
        // HTTP request renders as a 404 but which a Livewire component test
        // does not catch at all -- it propagates as the raw exception
        // instead of a response assertNotFound() can see.
        $user = User::query()->find($userId);

        abort_if($user === null, 404);

        $this->authorize('update', $user);

        $role = $this->roleChoice[$userId] ?? '';

        try {
            app(SetUserRoles::class)->handle($user, $role !== '' ? [$role] : []);
        } catch (LastAdministratorMustRemain $e) {
            // Surfaced through the error bag rather than left to escape as
            // a 500 -- see the class docblock and .github/mutations.json.
            // A fixed key (not one keyed by user id) because
            // last-admin-error, per the spec below, is a single element the
            // operator reads regardless of which row triggered it.
            $this->addError('lastAdministrator', $e->getMessage());

            // Snap the pending choice back to what is actually stored, so
            // the select does not keep showing a role change that was
            // refused.
            $this->roleChoice[$userId] = $user->refresh()->roles->pluck('name')->first() ?? '';

            return;
        }

        $this->resetErrorBag('lastAdministrator');
    }

    public function render(): View
    {
        $users = User::query()->with('roles')->orderBy('name')->get();

        foreach ($users as $user) {
            if (! array_key_exists($user->id, $this->roleChoice)) {
                $this->roleChoice[$user->id] = $user->roles->pluck('name')->first() ?? '';
            }
        }

        return view('livewire.admin.users', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }
}
