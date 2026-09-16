<?php

declare(strict_types=1);

use App\Actions\Files\RestoreFile;
use App\Actions\Files\StoreFileVersion;
use App\Actions\Files\TrashFile;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->user = User::factory()->create();
    $this->dir = Directory::factory()->create();
});

function uploadInto(string $name, string $contents = 'x'): File
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return app(StoreFileVersion::class)->handle(test()->user, test()->dir, $path, $name, 'text/plain');
}

it('refuses a new file in an archived period', function () {
    $this->travelTo('2026-03-15');
    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 3, 'archived_at' => now()]);

    expect(fn () => uploadInto('a.txt'))->toThrow(PeriodIsArchived::class);
});

it('refuses a new version of an existing file in an archived period', function () {
    $this->travelTo('2026-03-15');
    uploadInto('a.txt', 'first');

    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 3, 'archived_at' => now()]);

    // A file's versions all live under its creation period, so adding one to an
    // archived file would write into a period declared closed.
    expect(fn () => uploadInto('a.txt', 'second'))->toThrow(PeriodIsArchived::class);
});

it('allows uploads in an open period', function () {
    $this->travelTo('2026-03-15');
    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 2, 'archived_at' => now()]);

    expect(uploadInto('a.txt')->name)->toBe('a.txt');
});

it('allows uploads when the period row exists but is not archived', function () {
    $this->travelTo('2026-03-15');
    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 3, 'archived_at' => null]);

    expect(uploadInto('a.txt')->name)->toBe('a.txt');
});

it('refuses to restore a trashed file into an archived period', function () {
    $this->travelTo('2026-03-15');
    $file = uploadInto('a.txt');
    app(TrashFile::class)->handle($file);

    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 3, 'archived_at' => now()]);

    // A file's period is fixed at creation, so restoring it writes back into
    // that same period -- an archived one must refuse it just as it refuses
    // a new version.
    expect(fn () => app(RestoreFile::class)->handle(File::withTrashed()->findOrFail($file->id)))
        ->toThrow(PeriodIsArchived::class);
});
