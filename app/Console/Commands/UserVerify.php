<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EmailKey;
use Illuminate\Console\Command;

/**
 * The safety valve item/email-verification-decided (issue #161) requires
 * before that item is safe to ship at all.
 *
 * User now implements MustVerifyEmail for real, and App\Livewire\Settings\
 * Profile nulls email_verified_at the moment an operator changes their own
 * email -- correctly, since the new address has proved nothing yet. But
 * config/mail.php defaults MAIL_MAILER to 'log', which is also what a
 * shipped container ships with, so the only verification link that change
 * produces lands in the container log, not an inbox. An operator who
 * changes their own email is instantly locked out of every `verified` route
 * -- including Settings, the very page that could undo the mistake -- with
 * no recovery that does not involve grepping that log for a token by hand.
 *
 * Sibling to doccum:user:reset-password (App\Console\Commands\
 * UserResetPassword) and the same trust argument: a shell able to run
 * `php artisan doccum:user:verify` already has total control of the
 * instance, so there is nothing here for a would-be attacker to gain that
 * direct database access would not already hand them. Unlike that command,
 * there is no token to mint and no link to build -- verifying is one write,
 * so this command just makes it.
 */
class UserVerify extends Command
{
    protected $signature = 'doccum:user:verify
        {email : The account to mark verified}';

    protected $description = "Mark a user's email as verified, without sending mail";

    public function handle(): int
    {
        $email = EmailKey::of((string) $this->argument('email'));

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error(sprintf('No user found for %s.', $email));

            return self::FAILURE;
        }

        if ($user->hasVerifiedEmail()) {
            $this->components->info(sprintf('%s is already verified.', $user->email));

            return self::SUCCESS;
        }

        $user->markEmailAsVerified();

        $this->components->info(sprintf('%s is now verified.', $user->email));

        return self::SUCCESS;
    }
}
