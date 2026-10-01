<?php

declare(strict_types=1);

use App\Support\EmbeddedStorage;

beforeEach(function () {
    $this->envFile = sys_get_temp_dir().'/doccum-storage-'.uniqid().'.env';
    config()->set('doccum.storage.embedded_env', $this->envFile);
});

afterEach(fn () => @unlink($this->envFile));

it('reports inactive when no credentials file exists', function () {
    expect(EmbeddedStorage::isActive())->toBeFalse()
        ->and(EmbeddedStorage::credentials())->toBeNull();
});

it('parses generated credentials', function () {
    file_put_contents($this->envFile, "ROOT_ACCESS_KEY=doccum\nROOT_SECRET_KEY=abc123\nDOCCUM_EMBEDDED_ROOT=/data/objects\n");

    expect(EmbeddedStorage::isActive())->toBeTrue()
        ->and(EmbeddedStorage::credentials())->toMatchArray([
            'key' => 'doccum',
            'secret' => 'abc123',
        ]);
});

it('ignores comments and blank lines', function () {
    file_put_contents($this->envFile, "# generated\n\nROOT_ACCESS_KEY=doccum\nROOT_SECRET_KEY=abc123\n");

    expect(EmbeddedStorage::credentials()['secret'])->toBe('abc123');
});

it('treats a file missing the password as inactive', function () {
    file_put_contents($this->envFile, "ROOT_ACCESS_KEY=doccum\n");

    expect(EmbeddedStorage::isActive())->toBeFalse();
});

/**
 * The embedded server reads this same file as its own environment
 * (docker/bin/doccum-storage sources it), so a MinIO-era file is not merely
 * a different spelling of the same thing: the server it was written for is
 * gone. Reading it as credentials would hand the app a key the running
 * server does not accept, and every object would 403 rather than fail at
 * boot where an operator can see it. 48-doccum-storage.sh refuses that
 * volume outright; this pins the half of the contract that lives in PHP.
 */
it('does not accept a MinIO-era credentials file', function () {
    file_put_contents($this->envFile, "MINIO_ROOT_USER=doccum\nMINIO_ROOT_PASSWORD=abc123\n");

    expect(EmbeddedStorage::isActive())->toBeFalse()
        ->and(EmbeddedStorage::credentials())->toBeNull();
});
