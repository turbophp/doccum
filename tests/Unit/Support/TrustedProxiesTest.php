<?php

declare(strict_types=1);

use App\Support\TrustedProxies;

/**
 * Pure parsing, no container or database, matching the rest of tests/Unit:
 * bootstrap/app.php's reasoning for the default lives as comments there, but
 * the parsing that reasoning drives is small enough to prove directly.
 */
it('defaults to private and loopback ranges when unset, not "*" and not empty', function () {
    $resolved = TrustedProxies::resolve(null);

    expect($resolved)->toBeArray()
        ->and($resolved)->not->toBe([])
        ->and($resolved)->not->toContain('*')
        ->and($resolved)->toContain('127.0.0.1/8')
        ->and($resolved)->toContain('172.16.0.0/12');
});

it('trusts every proxy when set to "*"', function () {
    expect(TrustedProxies::resolve('*'))->toBe('*');
});

it('splits a comma-separated list into trimmed entries', function () {
    expect(TrustedProxies::resolve(' 10.1.2.3 , 203.0.113.0/24,198.51.100.7 '))
        ->toBe(['10.1.2.3', '203.0.113.0/24', '198.51.100.7']);
});

it('trusts nothing when set to an explicit empty string', function () {
    expect(TrustedProxies::resolve(''))->toBe([]);
});

it('drops empty entries from a trailing comma', function () {
    expect(TrustedProxies::resolve('10.1.2.3,'))->toBe(['10.1.2.3']);
});
