<?php

declare(strict_types=1);

use App\Support\EmailKey;

it('lowercases plain ascii', function () {
    expect(EmailKey::of('Alice@Example.com'))->toBe('alice@example.com');
});

it('treats emails differing only by case as the same key', function () {
    expect(EmailKey::of('Alice@Example.com'))->toBe(EmailKey::of('alice@example.com'));
});

it('trims incidental surrounding whitespace', function () {
    expect(EmailKey::of('  alice@example.com  '))->toBe('alice@example.com');
});

it('is idempotent', function () {
    $once = EmailKey::of('Alice@Example.com');

    expect(EmailKey::of($once))->toBe($once);
});

it('does not fold accents, unlike NameKey', function () {
    // Unlike App\Support\NameKey, EmailKey states no accent-folding rule --
    // there is no report motivating one, so it stays a pure case fold and an
    // accented character is left exactly as submitted (aside from case).
    expect(EmailKey::of('É@example.com'))->toBe('é@example.com');
});
