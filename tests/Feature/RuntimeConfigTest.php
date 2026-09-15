<?php

declare(strict_types=1);

use App\Exceptions\RuntimeConfigUnreadable;
use App\Support\RuntimeConfig;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(fn () => @unlink($this->file));

it('reports absence before anything is written', function () {
    expect(RuntimeConfig::exists())->toBeFalse()
        ->and(RuntimeConfig::read())->toBe([]);
});

it('round trips a payload', function () {
    RuntimeConfig::write(['database' => ['host' => 'db', 'password' => 'hunter2']]);

    expect(RuntimeConfig::exists())->toBeTrue()
        ->and(RuntimeConfig::read())->toBe(['database' => ['host' => 'db', 'password' => 'hunter2']]);
});

it('does not store secrets in plaintext on disk', function () {
    RuntimeConfig::write(['storage' => ['secret' => 'super-secret-value']]);

    $raw = file_get_contents($this->file);

    expect($raw)->not->toContain('super-secret-value')
        ->and($raw)->not->toContain('storage');
});

it('writes a readable envelope around the ciphertext', function () {
    RuntimeConfig::write(['a' => 1]);

    $raw = json_decode(file_get_contents($this->file), true);

    expect($raw)->toHaveKeys(['doccum', 'payload'])
        ->and($raw['doccum'])->toBe(1);
});

it('writes the file private to its owner', function () {
    RuntimeConfig::write(['a' => 1]);

    expect(substr(sprintf('%o', fileperms($this->file)), -3))->toBe('600');
});

it('refuses to guess when the ciphertext cannot be decrypted', function () {
    RuntimeConfig::write(['a' => 1]);
    file_put_contents($this->file, json_encode(['doccum' => 1, 'payload' => 'not-valid-ciphertext']));

    expect(fn () => RuntimeConfig::read())->toThrow(RuntimeConfigUnreadable::class);
});

it('treats a corrupt envelope as unreadable rather than empty', function () {
    file_put_contents($this->file, '{ this is not json');

    expect(fn () => RuntimeConfig::read())->toThrow(RuntimeConfigUnreadable::class);
});

it('forgets the file', function () {
    RuntimeConfig::write(['a' => 1]);
    RuntimeConfig::forget();

    expect(RuntimeConfig::exists())->toBeFalse();
});
