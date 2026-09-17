<?php

declare(strict_types=1);

use App\Models\User;

/**
 * item/user-reset-password-command (issue #61): the forgot-password POST
 * must say mail is not configured, rather than claim it emailed anything,
 * whenever the mailer is "log" or "array" -- and it must say EXACTLY the
 * same thing whether or not the submitted address belongs to a real
 * account. That second half is the point of this file: a version of this
 * feature that only narrowed the "success" branch would still leak account
 * existence through the "failure" branch's stock "we can't find a user"
 * message, so the tests below assert the two branches are byte-identical,
 * not merely that each one is "some error".
 */

it('says mail is not configured for a real account when the mailer is log', function () {
    config()->set('mail.default', 'log');

    $user = User::factory()->create();

    $response = $this->post(route('password.email'), ['email' => $user->email]);

    $response->assertSessionHasNoErrors();
    expect(session('status'))->toContain('Mail is not configured on this server');
});

it('says mail is not configured for a real account when the mailer is array', function () {
    // "array" is phpunit.xml's own MAIL_MAILER for the whole suite, so this
    // exercises the suite's baseline configuration deliberately rather than
    // only a value set up specifically for this test.
    config()->set('mail.default', 'array');

    $user = User::factory()->create();

    $response = $this->post(route('password.email'), ['email' => $user->email]);

    $response->assertSessionHasNoErrors();
    expect(session('status'))->toContain('Mail is not configured on this server');
});

it('gives a real account and a nonexistent one the identical flashed status when mail cannot deliver', function () {
    config()->set('mail.default', 'array');

    $user = User::factory()->create();

    $forRealAccount = $this->post(route('password.email'), ['email' => $user->email]);
    $statusForRealAccount = session('status');

    $forNoAccount = $this->post(route('password.email'), ['email' => 'definitely-not-a-real-account@example.test']);
    $statusForNoAccount = session('status');

    // assertSessionHasNoErrors() on the SECOND response is the load-bearing
    // half of this test, not the status comparison below it. Laravel's
    // flash data survives one extra request after it stops being re-flashed
    // (Session\Store::ageFlashData()), so if the "no account" branch
    // stopped re-flashing "status" entirely and started flashing an
    // 'email' validation error instead -- i.e. exactly what regressing to
    // Fortify's stock failure response looks like -- $statusForNoAccount
    // would still read back the FIRST call's now-stale value and the plain
    // equality check below would keep passing right through that
    // regression. Only the errors-bag assertion actually catches it.
    $forRealAccount->assertSessionHasNoErrors();
    $forNoAccount->assertSessionHasNoErrors();

    expect($statusForRealAccount)->not->toBeNull();
    expect($statusForRealAccount)->toBe($statusForNoAccount);
});

it('gives a real account and a nonexistent one byte-identical JSON when mail cannot deliver', function () {
    config()->set('mail.default', 'log');

    $user = User::factory()->create();

    $forRealAccount = $this->postJson(route('password.email'), ['email' => $user->email]);
    $forNoAccount = $this->postJson(route('password.email'), ['email' => 'definitely-not-a-real-account@example.test']);

    $forRealAccount->assertOk();
    $forNoAccount->assertOk();

    // Comparing the decoded body, not assertExactJson against a literal --
    // the literal is a paraphrase of the real message and would drift
    // silently out of sync with App\Http\Responses\Fortify\
    // MailNotConfiguredResponse. What must hold is that the two calls
    // produced the exact same body, whatever it says.
    expect($forRealAccount->json())->toBe($forNoAccount->json());
});

it('leaves the forgot-password flow unaffected when a real mailer is configured', function () {
    config()->set('mail.default', 'smtp');

    $user = User::factory()->create();

    // Deliberately the plain form post, not postJson(): what must hold
    // here is Fortify's own stock behaviour (assertSessionHasErrors('email')
    // for no match, matching the same pattern this suite's own
    // RegistrationTest and AuthenticationTest already rely on for Fortify's
    // stock validation-style failure responses) restored untouched, not a
    // specific status code this repo has no source available to confirm.
    $forRealAccount = $this->post(route('password.email'), ['email' => $user->email]);
    $forNoAccount = $this->post(route('password.email'), ['email' => 'definitely-not-a-real-account@example.test']);

    // With a real mailer configured the two branches are NOT expected to
    // agree any more -- Fortify's stock behaviour (whatever it already
    // reveals or does not reveal) is restored untouched. This is the
    // contrast case for the tests above: same two inputs, opposite outcome,
    // once the mailer stops being the reason to hide the difference.
    $forRealAccount->assertSessionHasNoErrors();
    expect(session('status'))
        ->not->toBeNull()
        ->not->toContain('Mail is not configured');

    $forNoAccount->assertSessionHasErrors('email');
});
