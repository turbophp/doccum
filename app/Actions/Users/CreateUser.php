<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\EmailKey;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

/**
 * The one place that validates and creates a user, then gives them a home.
 *
 * item/admin-users (issue #18): before this, App\Actions\Fortify\
 * CreateNewUser was the only code that did this, reachable only from
 * self-registration and the first-run installer. Admin user creation (spec
 * §10) needs the same three things -- a validated user, an assigned role, a
 * home directory -- so this is that logic pulled out from under
 * CreateNewUser, which now delegates to it. There is exactly one code path
 * that gives a new user a home directory, which is what makes "a user
 * created through the UI has a home directory" true by construction rather
 * than by two call sites happening to agree.
 *
 * This action does NOT authorise -- CLAUDE.md: actions never authorise,
 * callers do, through a Policy. UserPolicy::create() is that check, made by
 * every caller before this runs.
 *
 * item/email-verification-decided (issue #161): this action is ALSO the one
 * Fortify's own CreateNewUser delegates to for self-registration, so
 * "verified" cannot be decided in here unconditionally -- doing so would
 * verify a self-registered account too, which is exactly the case the
 * decision requires to prove itself. $verified is therefore an explicit
 * parameter the CALLER supplies, the same "actions never decide, callers
 * do" shape CLAUDE.md already requires for authorisation: App\Livewire\
 * Admin\Users::save() passes true because reaching it at all already
 * required the users.manage permission -- an operator vouching for the
 * address -- while App\Actions\Fortify\CreateNewUser leaves it at the
 * default, so a self-registered user is created exactly as unverified as
 * before. email_verified_at is deliberately not mass-assigned above:
 * it is not in User's #[Fillable(...)] list, so it is set via forceFill()
 * after create(), the same pattern App\Actions\Fortify\ResetUserPassword
 * already uses for a guarded column.
 */
class CreateUser
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function handle(array $input, ?string $role, bool $verified = false): User
    {
        // Fold before validating, not merely on save: emailRules()'s
        // Rule::unique() compares whatever is in $input against the `email`
        // column verbatim, so uniqueness only means the same thing on every
        // driver if the value it compares is already folded. See issue #59
        // and App\Support\EmailKey. Identical to CreateNewUser's own reason
        // for folding here, because this is now the code CreateNewUser
        // delegates to.
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

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Preserved from CreateNewUser precisely: a caller-chosen role that
        // does not name a role that exists is silently skipped rather than
        // raising, so a misconfigured auth.default_role setting (or a stale
        // choice in the admin form) leaves a roleless user rather than a
        // 500. See CreateNewUser's own docblock for the original reasoning.
        if (is_string($role) && Role::where('name', $role)->exists()) {
            $user->assignRole($role);
        }

        app(CreateHomeDirectory::class)->handle($user);

        return $user;
    }
}
