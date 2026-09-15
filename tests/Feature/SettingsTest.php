<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\Settings;

it('falls back to the config default when no row exists', function () {
    expect(app(Settings::class)->get('auth.public_signup'))->toBeFalse()
        ->and(app(Settings::class)->get('instance.name'))->toBe('doccum');
});

it('prefers a stored row over the config default', function () {
    Setting::create(['key' => 'auth.public_signup', 'value' => true]);
    app(Settings::class)->flush();

    expect(app(Settings::class)->get('auth.public_signup'))->toBeTrue();
});

it('persists a value and invalidates the cache', function () {
    $settings = app(Settings::class);

    expect($settings->get('instance.name'))->toBe('doccum');
    $settings->set('instance.name', 'Acme Docs');

    expect($settings->get('instance.name'))->toBe('Acme Docs')
        ->and(Setting::where('key', 'instance.name')->value('value'))->toBe('Acme Docs');
});

it('returns the supplied default for an unknown key', function () {
    expect(app(Settings::class)->get('nope.not.here', 'fallback'))->toBe('fallback');
});

it('boots correctly with an empty settings table', function () {
    expect(Setting::count())->toBe(0)
        ->and(app(Settings::class)->get('directories.auto_home'))->toBeTrue();
});
