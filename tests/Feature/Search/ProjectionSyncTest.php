<?php

declare(strict_types=1);

use App\Actions\Directories\MoveDirectory;
use App\Actions\Files\StoreFileVersion;
use App\Jobs\ExtractText;
use App\Models\Directory;
use App\Models\File;
use App\Models\SearchDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('indexes a directory when it is created', function () {
    $dir = Directory::factory()->create(['name' => 'Invoices']);

    expect(SearchDocument::where('subject_type', 'directory')->where('subject_id', $dir->id)->exists())
        ->toBeTrue();
});

it('reindexes when a name changes', function () {
    $dir = Directory::factory()->create(['name' => 'Old']);
    $dir->update(['name' => 'New']);

    expect(SearchDocument::where('subject_id', $dir->id)->value('title'))->toBe('New');
});

it('forgets a trashed file', function () {
    $file = File::factory()->create();
    expect(SearchDocument::where('subject_type', 'file')->count())->toBe(1);

    $file->delete();

    // A trashed file must stop being findable immediately, or search becomes a
    // way to read what was deleted.
    expect(SearchDocument::where('subject_type', 'file')->count())->toBe(0);
});

it('reindexes a whole subtree when a directory moves', function () {
    $a = Directory::factory()->create();
    $b = Directory::factory()->create();
    $child = Directory::factory()->for($a, 'parent')->create();
    $grandchild = Directory::factory()->for($child, 'parent')->create();

    app(MoveDirectory::class)->handle($child->fresh(), $b->fresh());

    // Ancestors decide who may see a result, so a move that leaves them stale
    // grants or denies access incorrectly for everything beneath it.
    expect(SearchDocument::where('subject_id', $grandchild->id)->value('ancestor_ids'))
        ->toBe([$b->id, $child->id, $grandchild->id]);
});

it('reindexes a file when its text is extracted', function () {
    // The body must carry the EXTRACTED text, not merely the filename. A file
    // is first indexed at upload, when its contents are not known yet, so
    // without a reindex after extraction its text stays unfindable until
    // something else happens to touch the file.
    //
    // No "before" assertion: the test queue is synchronous, so extraction has
    // already run by the time control returns from the upload. Verified
    // instead by removing the reindex in ExtractText and confirming this fails.
    Storage::fake('documents');
    $user = User::factory()->create();
    $dir = Directory::factory()->create();

    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, 'the tenant shall maintain the premises');
    $file = app(StoreFileVersion::class)->handle($user, $dir, $path, 'Lease.txt', 'text/plain');

    ExtractText::dispatchSync($file->currentVersion);

    expect(SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->value('body'))
        ->toContain('tenant shall maintain');
});
