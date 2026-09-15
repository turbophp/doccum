<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Actions\Users\CreateHomeDirectory;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * The one-time, first-run screen that creates the instance's first admin.
 *
 * Reachable only while no user exists at all (see RequireInstanceSetup), and
 * closed for good the moment one does -- doccum ships no default credentials.
 */
#[Title('Set up doccum')]
#[Layout('layouts::auth')]
class FirstRun extends Component
{
    use PasswordValidationRules, ProfileValidationRules;

    public string $instance_name = 'doccum';

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function submit(): Redirector|RedirectResponse
    {
        $validated = $this->validate([
            'instance_name' => ['required', 'string', 'max:191'],
            'name' => $this->nameRules(),
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $user->assignRole('admin');

        app(Settings::class)->set('instance.name', $validated['instance_name'], $user->id);
        app(CreateHomeDirectory::class)->handle($user);

        Auth::login($user);

        return redirect('/');
    }

    public function render()
    {
        return view('livewire.setup.first-run');
    }
}
