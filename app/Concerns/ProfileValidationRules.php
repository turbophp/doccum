<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Unique|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * Rule::unique() compares the submitted value byte-for-byte against the
     * `email` column, so it only means the same thing on every driver
     * because every caller folds the submitted value through
     * App\Support\EmailKey BEFORE calling validate() -- CreateNewUser,
     * FirstRun::submit() and Profile::updateProfileInformation() all do
     * this. The column itself is always folded too (User's saving hook), so
     * a folded submission compared against a folded column is a correct
     * equality check on every driver, with no collation dependency and no
     * per-call query rewrite needed here. See issue #59.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate usernames.
     *
     * Deliberately NOT included in profileRules(): the profile update form does
     * not submit a username, and adding a required rule there would break it.
     * A username namespaces the user's home directory, so its character set is
     * constrained to what is safe as a directory name. See spec §4.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function usernameRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'min:2',
            'max:64',
            'regex:/^[a-z0-9._-]+$/',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
