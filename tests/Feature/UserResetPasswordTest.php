<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * item/user-reset-password-command (issue #61). The command's own docblock
 * (App\Console\Commands\UserResetPassword) argues that a shell able to run
 * `php artisan doccum:user:reset-password` already has total control of the
 * instance -- unlike the forgot-password HTTP endpoint, telling that shell
 * "no such account" is not a second way to learn something an attacker
 * without shell access could not already read straight out of the
 * database. That is the decision this file tests: the command prints a
 * plain, named error for a missing account rather than pretending to
 * succeed, and does not need the forgot-password page's "identical
 * response either way" treatment.
 */
it('prints a one-time reset link that genuinely resets the password', function () {
    // "No mailer configured" is exactly the scenario this command exists
    // for -- see the class docblock's "Deliberately bypasses
    // Password::sendResetLink()" paragraph.
    config()->set('mail.default', 'log');

    $user = User::factory()->create();

    Artisan::call('doccum:user:reset-password', ['email' => $user->email]);
    // Squished so the console component's own word-wrapping of the
    // descriptive line (Illuminate's info() block wraps to terminal width;
    // $this->line($url) below does not) can never make a literal substring
    // check flaky depending on how long $user->email happens to be.
    $output = Str::squish(Artisan::output());

    expect($output)->toContain("One-time reset link for {$user->email}");
    expect($output)->toContain('expires in 60 minutes');

    // route('password.reset', ['token' => ..., 'email' => ...]) puts the
    // token in the PATH (/reset-password/{token}) and the email in the
    // query string -- pulling it back out of the printed URL, rather than
    // reading the database directly, is what proves the printed link
    // itself is the thing that works, not merely that SOME token exists.
    preg_match('#/reset-password/([^\s?]+)#', $output, $matches);
    expect($matches)->not->toBeEmpty();
    $token = $matches[1];

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('login', absolute: false));

    expect(Hash::check('a-brand-new-password', $user->refresh()->password))->toBeTrue();
});

it('matches an account regardless of the input email casing', function () {
    config()->set('mail.default', 'log');

    $user = User::factory()->create(['email' => 'someone@example.com']);

    Artisan::call('doccum:user:reset-password', ['email' => 'SomeOne@Example.com']);

    expect(Str::squish(Artisan::output()))->toContain('One-time reset link for someone@example.com');
});

it('fails loudly and by name for an address with no account, instead of a one-time link', function () {
    $exitCode = Artisan::call('doccum:user:reset-password', ['email' => 'nobody@example.test']);
    $output = Str::squish(Artisan::output());

    expect($exitCode)->toBe(Command::FAILURE);
    expect($output)->toContain('No user found for nobody@example.test.');
    expect($output)->not->toContain('/reset-password/');
});

it('builds the link on the host the operator names, scheme included', function () {
    // Forcing only the root is not enough, and the difference is the whole
    // point of this option: UrlGenerator::formatRoot() re-applies a scheme
    // from formatScheme(), which in console falls back to config('app.url')
    // -- http by default. So an https:// base that only forced the root
    // would come back out as http://, silently. That mistake shipped once
    // and CI caught it on all six legs (PR 82); this asserts it cannot
    // return.
    $user = User::factory()->create(['email' => 'locked-out@example.com']);

    Artisan::call('doccum:user:reset-password', [
        'email' => $user->email,
        '--url' => 'https://docs.example.test',
    ]);

    $output = Str::squish(Artisan::output());

    expect($output)->toContain('https://docs.example.test/reset-password/')
        ->and($output)->not->toContain('http://docs.example.test');
});

it('warns that the link is built on a default host when APP_URL is unset', function () {
    config()->set('doccum.app_url_is_set', false);

    $user = User::factory()->create(['email' => 'unset-app-url@example.com']);

    Artisan::call('doccum:user:reset-password', ['email' => $user->email]);

    // The token is genuine either way; what makes the link useless to a
    // remote administrator is the host, so the command says so rather than
    // printing something that merely looks right.
    expect(Str::squish(Artisan::output()))->toContain('APP_URL is not set');
});

it('does not warn about APP_URL when the operator names a host', function () {
    config()->set('doccum.app_url_is_set', false);

    $user = User::factory()->create(['email' => 'named-host@example.com']);

    Artisan::call('doccum:user:reset-password', [
        'email' => $user->email,
        '--url' => 'https://docs.example.test',
    ]);

    expect(Str::squish(Artisan::output()))->not->toContain('APP_URL is not set');
});
