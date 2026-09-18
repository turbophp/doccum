<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Concerns\PasswordValidationRules;
use App\Exceptions\LastAdministratorMustRemain;
use App\Livewire\Actions\Logout;
use App\Support\LastAdministrator;
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

        // The ORDER here is not a style choice and must not be "tidied".
        //
        // The delete has to come AFTER $logout(). Model::delete() ends in
        // performDeleteOnModel(), which sets $exists = false. SessionGuard::
        // logout() then calls cycleRememberToken() for any user with a
        // non-empty remember_token, and that reaches EloquentUserProvider::
        // updateRememberToken(), which calls $user->save() -- and save() on a
        // model with $exists = false is an INSERT. Deleting first therefore
        // RESURRECTS the row that was just deleted, silently: no error, the
        // redirect still happens, and the account is still there. Laravel's
        // own UserFactory sets remember_token, so every factory-made user
        // hits it. CI caught this on two tests, one of them pre-existing.
        //
        // But the refusal has to be decided BEFORE the logout, or the sole
        // administrator is logged out on their way to being told no. So the
        // question is asked here rather than caught from the delete: the hook
        // in User::booting() is still the guarantee that nothing bypasses,
        // and App\Support\LastAdministrator is the single implementation
        // both consult, so there is one rule rather than two that can drift.
        if (LastAdministrator::wouldBeLostByDeleting($user)) {
            $this->addError('password', LastAdministratorMustRemain::forUser($user)->getMessage());

            return;
        }

        $logout();

        $user->delete();

        $this->redirect('/', navigate: true);
    }
}
