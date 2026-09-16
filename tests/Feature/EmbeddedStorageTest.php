<?php

declare(strict_types=1);

use App\Support\EmbeddedStorage;

beforeEach(function () {
    $this->envFile = sys_get_temp_dir().'/minio-'.uniqid().'.env';
    config()->set('doccum.storage.embedded_env', $this->envFile);
});

afterEach(fn () => @unlink($this->envFile));

it('reports inactive when no credentials file exists', function () {
    expect(EmbeddedStorage::isActive())->toBeFalse()
        ->and(EmbeddedStorage::credentials())->toBeNull();
});

it('parses generated credentials', function () {
    file_put_contents($this->envFile, "MINIO_ROOT_USER=doccum\nMINIO_ROOT_PASSWORD=abc123\nDOCCUM_EMBEDDED_ROOT=/data/objects\n");

    expect(EmbeddedStorage::isActive())->toBeTrue()
        ->and(EmbeddedStorage::credentials())->toMatchArray([
            'key' => 'doccum',
            'secret' => 'abc123',
        ]);
});

it('ignores comments and blank lines', function () {
    file_put_contents($this->envFile, "# generated\n\nMINIO_ROOT_USER=doccum\nMINIO_ROOT_PASSWORD=abc123\n");

    expect(EmbeddedStorage::credentials()['secret'])->toBe('abc123');
});

it('treats a file missing the password as inactive', function () {
    file_put_contents($this->envFile, "MINIO_ROOT_USER=doccum\n");

    expect(EmbeddedStorage::isActive())->toBeFalse();
});
