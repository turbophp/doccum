<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Exceptions\LastAdministratorMustRemain;
use App\Exceptions\UsernameWouldBeAmbiguous;
use App\Support\EmailKey;
use App\Support\LastAdministrator;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booting(): void
    {
        // Refuses to delete the last holder of `users.manage`.
        //
        // item/admin-users (issue #18): a shipped doccum instance is
        // single-admin by construction, so today the sole admin can brick
        // it from Settings -> Profile with no guard at all --
        // App\Livewire\Settings\DeleteUserForm lets the authenticated user
        // delete their OWN account, unconditionally. No new admin page is
        // needed to reach that; it has always been reachable. The guard
        // belongs here, the one place nothing can bypass, the same shape
        // the email fold and the username guard in booted() below already
        // argue for on this model.
        //
        // The rule itself lives in App\Support\LastAdministrator, because
        // DeleteUserForm has to ask the same question BEFORE it logs the
        // operator out and so cannot rely on this hook. One implementation,
        // two callers, no drift.
        //
        // Registered in booting(), NOT beside the deleting hook's sibling
        // saving hooks in booted() below, and this placement is load-bearing
        // rather than cosmetic. Illuminate\Events\Dispatcher fires listeners
        // for one event in plain registration order (Dispatcher::listen()
        // appends to a FIFO array; invokeListeners() walks it in that same
        // order -- no priority mechanism exists). Spatie's HasRoles and
        // HasPermissions traits each register their OWN `deleting` listener
        // from bootHasRoles()/bootHasPermissions(), called during boot() --
        // and because User has no SoftDeletes (isForceDeleting() does not
        // exist on it), their guard clause `method_exists($model,
        // 'isForceDeleting') && ! $model->isForceDeleting()` is false, so
        // they unconditionally detach this user's role/permission pivot rows
        // on every delete(), soft or not. Model::bootIfNotBooted() always
        // runs booting() -> boot() -> booted(), in that order, so a listener
        // registered in booted() (where every other hook on this model
        // lives, and the natural place for this one too) would run AFTER
        // that detach already committed: the very row this guard exists to
        // read would already be gone, `$holder` below would always come
        // back false, and the guard would be a silent no-op on every single
        // delete -- passing every test that does not mutation-check it and
        // failing the one that does, which CLAUDE.md is explicit is worse
        // than no guard at all. Registering here, before boot() registers
        // those two listeners, is what makes this one run first instead.
        static::deleting(static function (User $user): void {
            if (LastAdministrator::wouldBeLostByDeleting($user)) {
                throw LastAdministratorMustRemain::forUser($user);
            }
        });
    }

    protected static function booted(): void
    {
        // The authoritative fold: every save, from any path -- the three
        // application write sites also fold before validating (so
        // Rule::unique() in ProfileValidationRules compares folded values
        // too), but this is the one hook nothing can bypass, the same shape
        // as File/Directory's name_key maintenance. See App\Support\EmailKey
        // and issue #59. Unlike name_key, there is no separate column: the
        // folded value IS the stored value, because nothing needs email's
        // original casing preserved for display.
        static::saving(static function (User $user): void {
            $user->email = EmailKey::of((string) $user->email);
        });

        // The username's own authoritative guard, and deliberately a hook
        // rather than one more validation rule. AuthenticateUser resolves a
        // login identifier by asking whether it contains `@` -- with one it
        // looks up an email, without one a username -- so that dispatch is
        // unambiguous only while the two character sets stay disjoint.
        //
        // usernameRules() already excludes `@` at both application write
        // paths, and before this hook that was the ONLY thing keeping them
        // disjoint: a convention every future write path has to remember,
        // with nothing catching one that forgets. UserFactory is already
        // such a path -- it sets a username Faker happens to produce in the
        // right shape, validated by nothing. Authentication should not rest
        // on a convention, so this raises instead, the same "one hook
        // nothing can bypass" shape as the email fold above.
        static::saving(static function (User $user): void {
            $username = mb_strtolower(trim((string) $user->username));

            if (str_contains($username, '@')) {
                throw UsernameWouldBeAmbiguous::forUsername($username);
            }

            $user->username = $username;
        });
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
