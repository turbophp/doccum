<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\CreateHomeDirectory;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Settings;
use App\Support\EmailKey;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Fold before validating, not merely on save: emailRules()'s
        // Rule::unique() compares whatever is in $input against the `email`
        // column verbatim, so uniqueness only means the same thing on every
        // driver if the value it compares is already folded. See issue #59
        // and App\Support\EmailKey.
        if (isset($input['email'])) {
            $input['email'] = EmailKey::of($input['email']);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'username' => $this->usernameRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $role = app(Settings::class)->get('auth.default_role');

        if (is_string($role) && Role::where('name', $role)->exists()) {
            $user->assignRole($role);
        }

        app(CreateHomeDirectory::class)->handle($user);

        return $user;
    }
}
