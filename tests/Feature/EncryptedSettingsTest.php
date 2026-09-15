<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\Settings;

it('round trips a secret', function () {
    app(Settings::class)->setSecret('storage.secret', 'a-very-secret-value');

    expect(app(Settings::class)->get('storage.secret'))->toBe('a-very-secret-value');
});

it('does not store a secret in plaintext', function () {
    app(Settings::class)->setSecret('storage.secret', 'a-very-secret-value');

    $raw = Setting::where('key', 'storage.secret')->value('value');

    expect(json_encode($raw))->not->toContain('a-very-secret-value');
});

it('marks which keys are secret', function () {
    app(Settings::class)->setSecret('storage.secret', 'x');
    app(Settings::class)->set('instance.name', 'Acme');

    expect(app(Settings::class)->isSecret('storage.secret'))->toBeTrue()
        ->and(app(Settings::class)->isSecret('instance.name'))->toBeFalse();
});

it('leaves ordinary settings readable', function () {
    app(Settings::class)->set('instance.name', 'Acme');

    expect(Setting::where('key', 'instance.name')->value('value'))->toBe('Acme');
});

it('still falls back to config for an unset secret', function () {
    expect(app(Settings::class)->get('storage.secret', 'fallback'))->toBe('fallback');
});
