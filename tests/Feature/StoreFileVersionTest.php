<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->user = User::factory()->create();
    $this->dir = Directory::factory()->create();
});

function upload(string $contents = 'hello'): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return $path;
}

it('creates a file and its first version', function () {
    $file = app(StoreFileVersion::class)
        ->handle($this->user, $this->dir, upload('contents'), 'Report.pdf', 'application/pdf');

    expect($file->name)->toBe('Report.pdf')
        ->and($file->directory_id)->toBe($this->dir->id)
        ->and($file->mime)->toBe('application/pdf')
        ->and($file->size)->toBe(8)
        ->and($file->created_by)->toBe($this->user->id)
        ->and($file->versions)->toHaveCount(1)
        ->and($file->currentVersion->version_number)->toBe(1);
});

it('puts the bytes at the version object key', function () {
    $file = app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('abc'), 'a.txt');

    expect(Storage::disk('documents')->get($file->currentVersion->object_key))->toBe('abc');
});

it('records a sha256 checksum', function () {
    $file = app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('abc'), 'a.txt');

    expect($file->checksum)->toBe(hash('sha256', 'abc'))
        ->and($file->currentVersion->checksum)->toBe(hash('sha256', 'abc'));
});

it('adds a version when the same name is uploaded again', function () {
    $first = app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('one'), 'a.txt');
    $second = app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('two'), 'a.txt');

    expect($second->id)->toBe($first->id)
        ->and(File::count())->toBe(1)
        ->and($second->versions()->count())->toBe(2)
        ->and($second->currentVersion->version_number)->toBe(2)
        ->and($second->size)->toBe(3);
});

it('keeps the earlier version retrievable', function () {
    app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('one'), 'a.txt');
    $file = app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('two'), 'a.txt');

    $v1 = $file->versions()->where('version_number', 1)->firstOrFail();
    $v2 = $file->versions()->where('version_number', 2)->firstOrFail();

    expect(Storage::disk('documents')->get($v1->object_key))->toBe('one')
        ->and(Storage::disk('documents')->get($v2->object_key))->toBe('two');
});

it('treats the same name in another directory as a separate file', function () {
    $other = Directory::factory()->create();

    app(StoreFileVersion::class)->handle($this->user, $this->dir, upload(), 'a.txt');
    app(StoreFileVersion::class)->handle($this->user, $other, upload(), 'a.txt');

    expect(File::count())->toBe(2);
});

it('does not resurrect a trashed file of the same name', function () {
    $first = app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('one'), 'a.txt');
    $first->delete();

    $second = app(StoreFileVersion::class)->handle($this->user, $this->dir, upload('two'), 'a.txt');

    expect($second->id)->not->toBe($first->id)
        ->and($second->currentVersion->version_number)->toBe(1)
        ->and(File::withTrashed()->count())->toBe(2);
});

it('rolls back the database row if storing the object fails', function () {
    // Forcing the failure via a mocked DocumentStorage (rather than an
    // unreadable source path) matters: an unreadable path is rejected by the
    // checksum guard clause before the transaction ever opens, which would
    // make this test pass without ever exercising the rollback. The `once()`
    // expectation additionally proves the code genuinely reached the storage
    // call -- if it didn't, Mockery fails the test instead of silently
    // letting an untouched database look like a successful rollback.
    $this->mock(DocumentStorage::class, function ($mock) {
        $mock->shouldReceive('putVersion')
            ->once()
            ->andThrow(new RuntimeException('object store unavailable'));
    });

    expect(fn () => app(StoreFileVersion::class)
        ->handle($this->user, $this->dir, upload(), 'a.txt'))
        ->toThrow(RuntimeException::class, 'object store unavailable');

    expect(File::count())->toBe(0)
        ->and(FileVersion::count())->toBe(0);
});
