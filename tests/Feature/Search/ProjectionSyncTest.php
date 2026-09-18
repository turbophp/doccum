<?php

declare(strict_types=1);

use App\Actions\Directories\MoveDirectory;
use App\Actions\Files\StoreFileVersion;
use App\Jobs\ExtractText;
use App\Jobs\ReindexSearchDocument;
use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Models\SearchDocument;
use App\Models\User;
use App\Services\SearchIndexer;
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

it('indexes a property whose subject was soft-deleted independently, instead of erroring', function () {
    // issue #242: `subject` is a morphTo, so the related model's SoftDeletes
    // scope applies to resolving it exactly as it would to any other
    // relation -- once the file is trashed, $property->subject finds
    // nothing and resolves to null. SearchProjectionObserver::saved()
    // dispatches a reindex on every Property save regardless of that, via
    // ReindexSearchDocument -> SearchIndexer::index(), so this path is
    // reached whenever a property is edited after its subject was trashed,
    // not only in this direct test.
    $property = Property::factory()->create();
    $file = File::findOrFail($property->subject_id);
    $file->delete();

    expect($property->fresh()->subject)->toBeNull();

    // Today's forProperty() passes that null straight into directoryFor(),
    // typed as a non-nullable Model -- a TypeError, not a caught failure.
    // The nullable case is already handled one line below the call (the
    // consumers use $directory?->getKey() and $directory?->ancestorIds() ??
    // []), so this asserts the parameter type is made to agree.
    $document = app(SearchIndexer::class)->index($property->fresh());

    expect($document->directory_id)->toBeNull()
        ->and($document->ancestor_ids)->toBe([]);
});

it('does not resurrect a trashed file when a reindex lands after the trash', function () {
    // The container, not the suite, found this. In production the queue is
    // real: the reindex dispatched by an upload, or one dispatched by an
    // ExtractText still in flight, can RUN after the file has been trashed.
    // SearchProjectionObserver::deleted() forgets the projection inline, but
    // ReindexSearchDocument::handle() used to call index() unconditionally,
    // and index() is an updateOrCreate -- so the row came back and the trashed
    // file answered searches again.
    //
    // This test has to stage the ordering by hand, because QUEUE_CONNECTION is
    // sync here: every dispatch runs inline and in order, so the race cannot
    // occur on its own. Dispatching explicitly after the delete is what
    // reproduces what the workers do.
    $file = File::factory()->create();

    expect(SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->exists())
        ->toBeTrue();

    $file->delete();

    expect(SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->exists())
        ->toBeFalse();

    ReindexSearchDocument::dispatch(File::withTrashed()->findOrFail($file->id));

    expect(SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->exists())
        ->toBeFalse();
});
