<?php

declare(strict_types=1);

use App\Actions\Files\RestoreFile;
use App\Actions\Files\StoreFileVersion;
use App\Actions\Files\TrashFile;
use App\Exceptions\DuplicateFileName;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->user = User::factory()->create();
    $this->dir = Directory::factory()->create();
});

function put(string $name, string $contents = 'x'): File
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return app(StoreFileVersion::class)->handle(test()->user, test()->dir, $path, $name);
}

it('trashes a file without deleting its objects', function () {
    $file = put('a.txt');
    $key = $file->currentVersion->object_key;

    app(TrashFile::class)->handle($file);

    expect(File::count())->toBe(0)
        ->and(File::withTrashed()->count())->toBe(1)
        ->and(Storage::disk('documents')->exists($key))->toBeTrue();
});

it('restores a trashed file', function () {
    $file = put('a.txt');
    app(TrashFile::class)->handle($file);

    $restored = app(RestoreFile::class)->handle(File::withTrashed()->findOrFail($file->id));

    expect($restored->trashed())->toBeFalse()
        ->and(File::count())->toBe(1)
        ->and($restored->currentVersion)->not->toBeNull();
});

it('refuses to restore onto a name that was taken meanwhile', function () {
    $file = put('a.txt');
    app(TrashFile::class)->handle($file);
    put('a.txt');

    expect(fn () => app(RestoreFile::class)->handle(File::withTrashed()->findOrFail($file->id)))
        ->toThrow(DuplicateFileName::class);
});

it('leaves the trashed file trashed when restore is refused', function () {
    $file = put('a.txt');
    app(TrashFile::class)->handle($file);
    put('a.txt');

    try {
        app(RestoreFile::class)->handle(File::withTrashed()->findOrFail($file->id));
    } catch (DuplicateFileName) {
        // expected
    }

    expect(File::withTrashed()->findOrFail($file->id)->trashed())->toBeTrue();
});

it('refuses to restore onto a name that differs only by case', function () {
    $file = put('report.pdf');
    app(TrashFile::class)->handle($file);
    put('Report.pdf');

    expect(fn () => app(RestoreFile::class)->handle(File::withTrashed()->findOrFail($file->id)))
        ->toThrow(DuplicateFileName::class);
});

it('allows restoring onto a name that differs only by an accent', function () {
    $file = put("r\u{00E9}sum\u{00E9}.pdf"); // NFC "résumé.pdf"
    app(TrashFile::class)->handle($file);
    put('resume.pdf');

    $restored = app(RestoreFile::class)->handle(File::withTrashed()->findOrFail($file->id));

    expect($restored->trashed())->toBeFalse();
});

it('refuses to restore onto a name that reappeared in a different unicode normalisation form', function () {
    $nfc = "r\u{00E9}sum\u{00E9}.pdf"; // precomposed U+00E9
    $nfd = "re\u{0301}sume\u{0301}.pdf"; // "e" + combining acute U+0301

    $file = put($nfc);
    app(TrashFile::class)->handle($file);
    put($nfd);

    expect(fn () => app(RestoreFile::class)->handle(File::withTrashed()->findOrFail($file->id)))
        ->toThrow(DuplicateFileName::class);
});
