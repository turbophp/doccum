<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * item/email-verification-decided (issue #161). Sibling to
 * doccum:user:reset-password (App\Console\Commands\UserResetPassword and
 * tests/Feature/UserResetPasswordTest.php): the same "a shell that reaches
 * this command already has total control of the instance" argument, so a
 * missing account is a plain, named error rather than a disguised success.
 *
 * This is what makes item/email-verification-decided safe to ship: without
 * it, an operator who changes their own email through App\Livewire\
 * Settings\Profile -- which correctly nulls email_verified_at on change --
 * has no way back into any `verified` route on a container whose mailer
 * defaults to 'log'.
 */
it('verifies a named user', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $exitCode = Artisan::call('doccum:user:verify', ['email' => $user->email]);

    expect($exitCode)->toBe(Command::SUCCESS);
    expect(Str::squish(Artisan::output()))->toContain("{$user->email} is now verified.");
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatched(Verified::class);
});

it('matches an account regardless of the input email casing', function () {
    $user = User::factory()->unverified()->create(['email' => 'someone@example.com']);

    Artisan::call('doccum:user:verify', ['email' => 'SomeOne@Example.com']);

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('says a user is already verified rather than re-verifying them', function () {
    $user = User::factory()->create();

    Event::fake();

    $exitCode = Artisan::call('doccum:user:verify', ['email' => $user->email]);

    expect($exitCode)->toBe(Command::SUCCESS);
    expect(Str::squish(Artisan::output()))->toContain("{$user->email} is already verified.");

    Event::assertNotDispatched(Verified::class);
});

it('fails loudly and by name for an address with no account', function () {
    $exitCode = Artisan::call('doccum:user:verify', ['email' => 'nobody@example.test']);

    expect($exitCode)->toBe(Command::FAILURE);
    expect(Str::squish(Artisan::output()))->toContain('No user found for nobody@example.test.');
});
