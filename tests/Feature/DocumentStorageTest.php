<?php

declare(strict_types=1);

use App\Exceptions\ObjectMissingFromStorage;
use App\Models\File;
use App\Models\FileVersion;
use App\Services\DocumentStorage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->storage = app(DocumentStorage::class);
});

function sourceFile(string $contents = 'hello'): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return $path;
}

it('stores a version under its period prefix', function () {
    $file = File::factory()->create(['period_year' => 2024, 'period_month' => 3]);

    $key = $this->storage->putVersion($file, 1, sourceFile('contents'), 'Report.pdf');

    expect($key)->toBe("files/2024/03/{$file->uuid}/v1/Report.pdf")
        ->and(Storage::disk('documents')->exists($key))->toBeTrue()
        ->and(Storage::disk('documents')->get($key))->toBe('contents');
});

it('keeps every version of a file under one prefix', function () {
    $file = File::factory()->create(['period_year' => 2024, 'period_month' => 3]);

    $v1 = $this->storage->putVersion($file, 1, sourceFile('one'), 'a.txt');
    $v2 = $this->storage->putVersion($file, 2, sourceFile('two'), 'a.txt');

    expect($v1)->toStartWith("files/2024/03/{$file->uuid}/")
        ->and($v2)->toStartWith("files/2024/03/{$file->uuid}/")
        ->and(Storage::disk('documents')->get($v1))->toBe('one')
        ->and(Storage::disk('documents')->get($v2))->toBe('two');
});

it('stores a staging object outside the files prefix', function () {
    $key = $this->storage->putStaging('abc-123', sourceFile('draft'), 'Report.pdf');

    expect($key)->toBe('uploads/abc-123/Report.pdf')
        ->and($key)->not->toStartWith('files/')
        ->and(Storage::disk('documents')->exists($key))->toBeTrue();
});

it('reports existence and size', function () {
    $file = File::factory()->create();
    $key = $this->storage->putVersion($file, 1, sourceFile('12345'), 'a.txt');

    expect($this->storage->exists($key))->toBeTrue()
        ->and($this->storage->exists('files/nope'))->toBeFalse()
        ->and($this->storage->size($key))->toBe(5);
});

it('deletes objects', function () {
    $file = File::factory()->create();
    $key = $this->storage->putVersion($file, 1, sourceFile(), 'a.txt');

    $this->storage->delete($key);

    expect($this->storage->exists($key))->toBeFalse();
});

/**
 * issue #153: downloadToTemp() is the path ExtractText uses, and had the
 * identical shape readStream() had before #134 -- a bare RuntimeException
 * for a missing object, indistinguishable from any other storage failure.
 * The row and its current version both exist here; only the object behind
 * them is gone, e.g. `/data` restored without `objects/`, mirrored the same
 * way FileDownloadTest covers readStream(): put it, then delete it out from
 * under the still-live row, rather than simply never writing it.
 */
it('throws the typed exception for downloadToTemp when the object is missing from storage', function () {
    $file = File::factory()->create();
    $version = FileVersion::factory()->for($file)->create(['object_key' => 'files/missing/v1/a.txt']);

    Storage::disk('documents')->put($version->object_key, 'the bytes themselves');
    Storage::disk('documents')->delete($version->object_key);

    expect(fn () => $this->storage->downloadToTemp($version))
        ->toThrow(ObjectMissingFromStorage::class, "Object [{$version->object_key}] was not found in storage.");
});

it('issues a temporary url for a version', function () {
    Storage::disk('documents')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expires): string => "https://minio.test/{$path}?e={$expires->getTimestamp()}",
    );

    $version = FileVersion::factory()->create(['object_key' => 'files/2024/03/abc/v1/a.txt']);

    expect($this->storage->temporaryUrl($version))
        ->toStartWith('https://minio.test/files/2024/03/abc/v1/a.txt?e=');
});
