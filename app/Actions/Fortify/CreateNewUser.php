<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\CreateUser;
use App\Models\User;
use App\Services\Settings;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration entry point.
 *
 * item/admin-users (issue #18): the validate-create-assignRole-home-directory
 * logic that used to live here moved to App\Actions\Users\CreateUser, which
 * admin user creation also calls -- so there is exactly one code path that
 * gives a new user a home directory, and this is now a thin adapter over it
 * that supplies the one thing only self-registration has: the instance's
 * configured default role rather than an operator's choice.
 */
class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $role = app(Settings::class)->get('auth.default_role');

        return app(CreateUser::class)->handle($input, is_string($role) ? $role : null);
    }
}
