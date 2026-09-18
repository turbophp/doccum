<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Exceptions\LastAdministratorMustRemain;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DeleteUserForm extends Component
{
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();

        // item/admin-users (issue #18): delete() BEFORE $logout(), not
        // after -- the reverse of what this used to do
        // (tap(Auth::user(), $logout(...))->delete()), which called
        // $logout FIRST and only attempted delete() second. On a
        // single-admin instance (the shape doccum ships in by
        // construction) that meant the sole admin's own session was
        // already ended by the time User::booting()'s deleting hook threw
        // LastAdministratorMustRemain -- logged out AND greeted with an
        // uncaught exception, while still being the only admin left and
        // nothing actually deleted. Attempting delete() first and only
        // logging out on success means the sole admin instead sees a
        // readable refusal and stays logged in.
        //
        // Deliberately not a second, separately-computed check: that would
        // be a second place deciding the same thing, with its own chance to
        // drift from what User::booting() actually enforces. This IS that
        // same guard -- delete() is the one thing that can trigger it, so
        // calling it (and catching what it throws) is the "pre-check": the
        // hook below is still the guarantee (nothing bypasses it, e.g. a
        // console command or a future API), this is only the readable
        // manners layer in front of it.
        try {
            $user->delete();
            // Laravel's Model::delete() declares `@throws \LogicException`,
            // and phpstan honours a declared @throws exactly -- so it
            // concludes nothing else can come out of the call and reads the
            // catch below as dead. It is not dead: the guard is a `deleting`
            // event listener registered in User::booting(), and no static
            // analyser can see a throw raised from inside a model event.
            // Ignored at the line rather than added to phpstan-baseline.neon,
            // which CLAUDE.md says not to grow, and rather than restructuring
            // live code to fit the analyser's incomplete model of it. The
            // mutation entry proves the catch is reached.
            // @phpstan-ignore-next-line
        } catch (LastAdministratorMustRemain $e) {
            $this->addError('password', $e->getMessage());

            return;
        }

        $logout();

        $this->redirect('/', navigate: true);
    }
}
