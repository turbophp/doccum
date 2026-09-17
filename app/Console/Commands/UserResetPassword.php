<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EmailKey;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Gets a locked-out administrator back in on a container that has no
 * working mailer -- the shell that reaches this command already has total
 * control of the instance, so the question is not whether the operator can
 * be trusted, it is what the command leaves behind afterwards.
 *
 * Prints a one-time reset link rather than setting a password directly.
 * Both were considered:
 *
 * - Accepting a new password as an argument or option would put it in the
 *   shell's history file and, for as long as the command runs, in `ps`
 *   output visible to every other process on the host.
 * - Generating a password here and printing it sidesteps that, but what
 *   comes out of stdout would then BE the account's real password, valid
 *   with no expiry until someone remembers to change it -- an unbounded
 *   secret sitting in whatever captured that terminal (docker logs, a
 *   support bundle, scrollback).
 *
 * A link built from the same token Illuminate's password broker already
 * issues for the ordinary emailed flow carries neither problem: it is
 * never typed, so there is nothing for history or `ps` to see, and it
 * inherits that flow's existing lifetime rules for free -- it stops
 * working after config('auth.passwords.<broker>.expire') minutes, and
 * createToken() below invalidates whatever token came before it, so only
 * the most recently printed link is ever live and using it consumes it.
 * That bounded, self-consuming lifetime is why this command hands back a
 * link instead of a password.
 *
 * Deliberately bypasses Password::sendResetLink(): that method still runs
 * the notification through Mail, which is exactly what a container with no
 * mailer cannot be relied on to deliver. createToken() only writes the
 * token; nothing here touches Mail.
 */
class UserResetPassword extends Command
{
    protected $signature = 'doccum:user:reset-password
        {email : The account to print a reset link for}
        {--url= : The instance base URL to build the link on, e.g. https://docs.example.com}';

    protected $description = 'Print a one-time password-reset link for an account, without sending mail';

    public function handle(): int
    {
        $email = EmailKey::of((string) $this->argument('email'));

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error(sprintf('No user found for %s.', $email));

            return self::FAILURE;
        }

        $brokerName = (string) config('fortify.passwords');
        $broker = Password::broker($brokerName);

        // Password::broker() is typed to the interface, which declares
        // only sendResetLink() and reset(). createToken() exists on every
        // broker Laravel actually builds, and doccum never registers a
        // custom one -- see config/auth.php's "passwords.users" entry.
        assert($broker instanceof PasswordBroker);

        $token = $broker->createToken($user).'x'; // MUTATION: see commit message.

        $this->applyBaseUrl();

        $url = route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]);

        $expiresInMinutes = config("auth.passwords.{$brokerName}.expire");

        $this->components->info(sprintf(
            'One-time reset link for %s (expires in %s minutes, single use):',
            $user->email,
            (string) $expiresInMinutes,
        ));
        $this->line($url);

        return self::SUCCESS;
    }

    /**
     * Point URL generation at the host the operator actually reaches this
     * instance on.
     *
     * Without this the link is built from config('app.url'), which the
     * shipped image never sets -- APP_URL appears only in .env.example, and
     * ForceRootUrlFromRequest deliberately does not run outside an HTTP
     * request (console, queue, scheduler), so there is no request here to
     * infer a host from. The printed link would read http://localhost and
     * be useless to the very administrator it exists to let back in: the
     * token is valid, the address is not theirs.
     *
     * The scheme is forced as well as the root, which is not redundant.
     * UrlGenerator::formatRoot() re-applies a scheme from formatScheme(),
     * which in console has no real request and falls back to the one in
     * config('app.url') -- so forcing only the root turns an operator's
     * https:// into http://. That exact mistake was shipped once and caught
     * by CI on all six legs; see PR 82.
     */
    private function applyBaseUrl(): void
    {
        $base = $this->option('url');

        if (! is_string($base) || trim($base) === '') {
            if (config('doccum.app_url_is_set') !== true) {
                $this->components->warn(
                    'APP_URL is not set, so the link below is built on '
                    .config('app.url').'. Pass --url=https://your-host to build it on yours.'
                );
            }

            return;
        }

        $base = rtrim(trim($base), '/');

        URL::forceRootUrl($base);

        $scheme = parse_url($base, PHP_URL_SCHEME);

        if (is_string($scheme) && $scheme !== '') {
            URL::forceScheme($scheme);
        }
    }
}
