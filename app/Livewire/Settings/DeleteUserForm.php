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
        } catch (LastAdministratorMustRemain $e) {
            $this->addError('password', $e->getMessage());

            return;
        }

        $logout($user);

        $this->redirect('/', navigate: true);
    }
}
