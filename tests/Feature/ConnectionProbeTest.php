<?php

declare(strict_types=1);

use App\Services\ConnectionProbe;
use Illuminate\Support\Facades\Storage;

it('accepts a working sqlite database', function () {
    $path = sys_get_temp_dir().'/probe-'.uniqid().'.sqlite';
    touch($path);

    $result = app(ConnectionProbe::class)->database([
        'connection' => 'sqlite',
        'database' => $path,
    ]);

    expect($result->ok)->toBeTrue();

    @unlink($path);
});

it('rejects an unreachable database', function () {
    $result = app(ConnectionProbe::class)->database([
        'connection' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBeString()->not->toBeEmpty();
});

it('never repeats the password in a failure message', function () {
    $result = app(ConnectionProbe::class)->database([
        'connection' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'super-secret-value',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->not->toContain('super-secret-value');
});

it('accepts storage it can write to and clean up', function () {
    Storage::fake('probe');

    $result = app(ConnectionProbe::class)->storage(['disk' => 'probe']);

    expect($result->ok)->toBeTrue()
        ->and(Storage::disk('probe')->allFiles())->toBeEmpty();
});

it('rejects storage it cannot reach', function () {
    $result = app(ConnectionProbe::class)->storage([
        'endpoint' => 'http://127.0.0.1:1',
        'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'region' => 'us-east-1',
    ]);

    expect($result->ok)->toBeFalse();
});
