<?php

declare(strict_types=1);

use App\Support\RuntimeConfig;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(fn () => @unlink($this->file));

it('masks secrets when showing config', function () {
    RuntimeConfig::write([
        'database' => ['host' => 'db.internal', 'password' => 'super-secret-value'],
        'storage' => ['bucket' => 'papers', 'secret' => 'another-secret'],
    ]);

    $this->artisan('doccum:config:show')
        ->expectsOutputToContain('db.internal')
        ->expectsOutputToContain('papers')
        ->doesntExpectOutputToContain('super-secret-value')
        ->doesntExpectOutputToContain('another-secret')
        ->assertSuccessful();
});

it('resets the runtime config', function () {
    RuntimeConfig::write(['storage' => ['bucket' => 'papers']]);

    $this->artisan('doccum:config:reset')->assertSuccessful();

    expect(RuntimeConfig::exists())->toBeFalse();
});
