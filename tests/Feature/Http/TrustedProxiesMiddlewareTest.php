<?php

declare(strict_types=1);

use App\Models\User;

/**
 * End-to-end proof that bootstrap/app.php's TRUSTED_PROXIES default and
 * ForceRootUrlFromRequest actually work together over a real request, not
 * just that their pieces are individually correct in isolation (see
 * tests/Unit/Support/TrustedProxiesTest.php and
 * tests/Feature/Http/ForceRootUrlFromRequestTest.php for those).
 *
 * Both tests unset APP_URL for the request they make, then restore whatever
 * was there (typically .env.example's APP_URL=http://localhost:8000, copied
 * into .env by CI's "Prepare environment" step) -- getenv()/putenv() rather
 * than config()->set(), since DoccumServiceProvider's ForceRootUrlFromRequest
 * binding reads the raw environment, not config('app.url'), precisely so it
 * is decided fresh per request rather than once at boot (see that binding's
 * own comment).
 */
beforeEach(function () {
    $this->originalAppUrl = getenv('APP_URL');
    putenv('APP_URL');
});

afterEach(function () {
    if ($this->originalAppUrl === false) {
        putenv('APP_URL');
    } else {
        putenv("APP_URL={$this->originalAppUrl}");
    }
});

it('honours X-Forwarded-Proto/-Host from the default trusted (private) range', function () {
    // An instance with no users redirects every route to /setup; create one
    // so /login renders instead.
    User::factory()->create();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '172.17.0.5'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'proxy.example.test',
        ])
        ->get(route('login'));

    $response->assertOk();
    // The login form's own action attribute, https and on the forwarded
    // host -- exactly what the container smoke test asserts on the real
    // image, proved here instead against the framework wiring directly.
    $response->assertSee('action="https://proxy.example.test/login"', false);
});

it('ignores X-Forwarded-Proto/-Host from an address outside the default trusted range', function () {
    User::factory()->create();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example.test',
        ])
        ->get(route('login'));

    $response->assertOk();
    $response->assertDontSee('attacker.example.test', false);
});
