<?php

declare(strict_types=1);

use App\Support\Redact;

it('removes a secret that appears in the text', function () {
    expect(Redact::secrets('could not connect using hunter2xyz', ['hunter2xyz']))
        ->toBe('could not connect using [redacted]');
});

it('removes every occurrence', function () {
    expect(Redact::secrets('hunter2xyz then hunter2xyz', ['hunter2xyz']))
        ->toBe('[redacted] then [redacted]');
});

it('removes several different secrets', function () {
    expect(Redact::secrets('user=alice pass=hunter2xyz key=AKIAEXAMPLE', ['hunter2xyz', 'AKIAEXAMPLE']))
        ->toBe('user=alice pass=[redacted] key=[redacted]');
});

it('leaves text alone when no secret appears', function () {
    expect(Redact::secrets('connection refused', ['hunter2xyz']))->toBe('connection refused');
});

it('ignores values too short to redact safely', function () {
    // Blanking every "a" would destroy the message without protecting anything.
    expect(Redact::secrets('connection refused', ['a']))->toBe('connection refused');
});

it('ignores empty and non-string values', function () {
    expect(Redact::secrets('connection refused', ['', null, 12345, []]))->toBe('connection refused');
});
