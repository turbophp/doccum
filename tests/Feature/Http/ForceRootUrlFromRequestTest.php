<?php

declare(strict_types=1);

use App\Http\Middleware\ForceRootUrlFromRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The middleware's own decision, isolated from TrustProxies: constructed
 * directly with an explicit $appUrlIsUnset, the way
 * tests/Feature/RuntimeConfigProviderTest.php instantiates
 * RuntimeConfigServiceProvider directly rather than resolving it from the
 * container. Whether a *forwarded* request's scheme/host survive TrustProxies
 * intact is a separate concern, covered end-to-end by
 * tests/Feature/Http/TrustedProxiesMiddlewareTest.php.
 */
afterEach(function () {
    // forceRootUrl() mutates the shared 'url' singleton for the rest of this
    // process. An empty string is falsy, so UrlGenerator::formatRoot() falls
    // straight back through to the natural, per-request root instead of
    // treating '' as a value to use -- the actual "unforced" state, not
    // config('app.url') (which would keep every URL forced to it for the
    // rest of the suite, unlike production where nothing forces it at all
    // when this middleware never runs).
    URL::forceRootUrl('');

    // forceScheme() is sticky for the process in the same way, so it has to
    // be cleared too or every later test in this run inherits https.
    URL::forceScheme(null);
});

it('forces the root url and scheme from the request when APP_URL is unset', function () {
    $request = Request::create('https://proxy.example.test/reset-password', 'GET');

    $response = (new ForceRootUrlFromRequest(appUrlIsUnset: true))
        ->handle($request, fn () => response('next'));

    expect($response->getContent())->toBe('next')
        ->and(url('/reset-password'))->toBe('https://proxy.example.test/reset-password')
        ->and(url('/build/assets/app.js'))->toStartWith('https://proxy.example.test');
});

it('leaves url generation alone when APP_URL is configured', function () {
    $before = url('/reset-password');

    $request = Request::create('https://proxy.example.test/reset-password', 'GET');

    (new ForceRootUrlFromRequest(appUrlIsUnset: false))
        ->handle($request, fn () => response('next'));

    // Nothing forced the root, so it is exactly whatever it already was --
    // proxy.example.test never enters into it.
    expect(url('/reset-password'))->toBe($before)
        ->and(url('/reset-password'))->not->toContain('proxy.example.test');
});

it('always calls the next middleware regardless of appUrlIsUnset', function () {
    $request = Request::create('https://proxy.example.test/reset-password', 'GET');
    $called = false;

    (new ForceRootUrlFromRequest(appUrlIsUnset: false))->handle($request, function () use (&$called) {
        $called = true;

        return response('ok');
    });

    expect($called)->toBeTrue();
});
