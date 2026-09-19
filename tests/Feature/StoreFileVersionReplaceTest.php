<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

/**
 * StoreFileVersion::replace() -- issue #115's entry point, added by
 * item/api-presigned-upload. A SEPARATE file from StoreFileVersionTest.php
 * on purpose: this item's doneWhen requires handle()'s create-or-append
 * behaviour and StoreFileVersionTest to stay unchanged, and the cleanest
 * proof of that is a diff showing StoreFileVersionTest.php untouched, which
 * a new test living inside it would undermine.
 */
beforeEach(function () {
    Storage::fake('documents');
    $this->user = User::factory()->create();
    $this->dir = Directory::factory()->create();
});

function localFile(string $contents = 'hello'): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return $path;
}

it('appends a version to the file it is given', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    $replaced = app(StoreFileVersion::class)->replace($this->user, $file, localFile('v2 body'));

    expect($replaced->id)->toBe($file->id)
        ->and($replaced->versions()->count())->toBe(2)
        ->and($replaced->currentVersion->version_number)->toBe(2)
        ->and($replaced->checksum)->toBe(hash('sha256', 'v2 body'));
});

it('never creates a second file, regardless of how many times it is called', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    app(StoreFileVersion::class)->replace($this->user, $file, localFile('v2'));
    app(StoreFileVersion::class)->replace($this->user, $file->fresh(), localFile('v3'));

    // There is no File::create() anywhere in replace() -- this is the
    // structural guarantee issue #115 asks for, proven rather than merely
    // read off the source: no name argument exists for a caller to pass
    // wrong, so a second row can only appear if replace() itself creates
    // one, which it cannot.
    expect(File::count())->toBe(1)
        ->and($file->fresh()->versions()->count())->toBe(3);
});

it("does not rename the file -- the object key still carries the file's own name", function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'report.pdf']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    $replaced = app(StoreFileVersion::class)->replace($this->user, $file, localFile('v2'));

    expect($replaced->name)->toBe('report.pdf')
        ->and($replaced->currentVersion->object_key)->toContain('report.pdf');
});

it('keeps the earlier version retrievable after a replace', function () {
    // v1 set up through handle() -- the ordinary upload path -- rather than
    // a bare factory row, so its object_key points at bytes that genuinely
    // exist on the fake disk.
    $file = app(StoreFileVersion::class)->handle($this->user, $this->dir, localFile('one'), 'a.txt');

    $replaced = app(StoreFileVersion::class)->replace($this->user, $file, localFile('two'), 'text/plain');

    $v1 = $replaced->versions()->where('version_number', 1)->firstOrFail();
    $v2 = $replaced->versions()->where('version_number', 2)->firstOrFail();

    expect(Storage::disk('documents')->get($v1->object_key))->toBe('one')
        ->and(Storage::disk('documents')->get($v2->object_key))->toBe('two');
});

it('throws PeriodIsArchived and adds no version when the file\'s own period is archived', function () {
    $file = File::factory()->for($this->dir, 'directory')->create([
        'name' => 'a.txt',
        'period_year' => 2024,
        'period_month' => 3,
    ]);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    ArchivePeriod::factory()->create([
        'year' => 2024,
        'month' => 3,
        'archived_at' => now(),
    ]);

    expect(fn () => app(StoreFileVersion::class)->replace($this->user, $file, localFile('v2')))
        ->toThrow(PeriodIsArchived::class);

    expect($file->fresh()->versions()->count())->toBe(1);
});

it('rolls back the database row if storing the object fails', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    // Forcing the failure via a mocked DocumentStorage, the same shape
    // StoreFileVersionTest's own rollback test uses for handle(), and for
    // the identical reason: an unreadable source path is rejected by the
    // checksum guard clause before the transaction ever opens, which would
    // make this test pass without exercising the rollback at all.
    $this->mock(DocumentStorage::class, function ($mock) {
        $mock->shouldReceive('putVersion')
            ->once()
            ->andThrow(new RuntimeException('object store unavailable'));
    });

    expect(fn () => app(StoreFileVersion::class)
        ->replace($this->user, $file, localFile()))
        ->toThrow(RuntimeException::class, 'object store unavailable');

    expect($file->fresh()->versions()->count())->toBe(1);
});

it('fails outright rather than resurrecting a file that was trashed independently', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);
    $file->delete();

    // replace() re-fetches by id through the default (non-trashed) query
    // inside its own lock -- unlike handle(), which would silently CREATE a
    // fresh file under the same name for a trashed row (StoreFileVersionTest
    // proves that is handle()'s designed behaviour), replace() has no
    // create path to fall into, so a file trashed independently of its
    // directory between authorisation and this call fails loudly instead.
    expect(fn () => app(StoreFileVersion::class)->replace($this->user, $file, localFile('v2')))
        ->toThrow(ModelNotFoundException::class);

    expect(File::withTrashed()->count())->toBe(1);
});
