<?php

declare(strict_types=1);

use App\Actions\Directories\BuildDirectoryArchive;
use App\Enums\AccessLevel;
use App\Enums\ArchiveStatus;
use App\Jobs\ZipDirectory;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\DirectoryArchive;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// Zipping a directory is the one download that hands over many files at once,
// so what it contains is a security claim and not a convenience: the archive
// must hold exactly what its requester could have opened one file at a time,
// and it must reach nobody else.

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole('admin');

    $this->root = Directory::factory()->create(['name' => 'Cases']);
    $this->open = Directory::factory()->for($this->root, 'parent')->create(['name' => 'Open']);
    $this->secret = Directory::factory()->for($this->root, 'parent')->create(['name' => 'Sealed']);

    grant($this->root, $this->owner, AccessLevel::Manage);
});

/**
 * Upload through the component, so the file has real bytes on the fake disk --
 * an archive built from rows with no objects behind them would prove nothing.
 */
function storeFile(Directory $directory, User $user, string $name): File
{
    Livewire::actingAs($user)
        ->test(Browser::class, ['directory' => $directory])
        ->set('upload', UploadedFile::fake()->create($name, 4, 'application/pdf'))
        ->call('store')
        ->assertHasNoErrors();

    return File::query()
        ->where('directory_id', $directory->getKey())
        ->where('name', $name)
        ->firstOrFail();
}

it('zips every file the requester can reach, keeping the subtree structure', function () {
    storeFile($this->root, $this->owner, 'Top.pdf');
    storeFile($this->open, $this->owner, 'Nested.pdf');

    $archive = DirectoryArchive::create([
        'directory_id' => $this->root->id,
        'requested_by' => $this->owner->id,
        'status' => ArchiveStatus::Pending,
    ]);

    app(BuildDirectoryArchive::class)->handle($archive);

    expect($archive->fresh()->status)->toBe(ArchiveStatus::Ready)
        ->and($archive->fresh()->object_key)->not->toBeNull();

    // Read the finished object back out of the fake disk and look inside it.
    $local = tempnam(sys_get_temp_dir(), 'zip-assert-');
    file_put_contents($local, Storage::disk('documents')->get($archive->fresh()->object_key));

    $zip = new ZipArchive;
    expect($zip->open($local))->toBeTrue();

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($local);

    expect($entries)->toContain('Top.pdf')
        ->and($entries)->toContain('Open/Nested.pdf');
});

it('leaves out a subtree the requester cannot reach, even though it is inside the directory', function () {
    storeFile($this->root, $this->owner, 'Visible.pdf');
    storeFile($this->secret, $this->owner, 'Sealed.pdf');

    // A viewer granted the parent but NOT the sealed child. DirectoryAccess
    // inherits downward, so the grant has to be made on the two branches the
    // viewer may see rather than on the root.
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    grant($this->open, $viewer, AccessLevel::View);

    $archive = DirectoryArchive::create([
        'directory_id' => $this->root->id,
        'requested_by' => $viewer->id,
        'status' => ArchiveStatus::Pending,
    ]);

    app(BuildDirectoryArchive::class)->handle($archive);

    $local = tempnam(sys_get_temp_dir(), 'zip-assert-');
    file_put_contents($local, Storage::disk('documents')->get($archive->fresh()->object_key));

    $zip = new ZipArchive;
    $zip->open($local);
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($local);

    expect($entries)->not->toContain('Sealed/Sealed.pdf')
        ->and($entries)->not->toContain('Visible.pdf');
});

it('refuses to start an archive of a directory the viewer cannot see', function () {
    $stranger = User::factory()->create();
    $stranger->assignRole('member');

    Queue::fake();

    Livewire::actingAs($stranger)
        ->test(Browser::class)
        ->call('downloadDirectoryZip', $this->root->id)
        ->assertStatus(404);

    Queue::assertNothingPushed();
    expect(DirectoryArchive::count())->toBe(0);
});

it('queues the build rather than zipping inside the request', function () {
    Queue::fake();

    Livewire::actingAs($this->owner)
        ->test(Browser::class, ['directory' => $this->root])
        ->call('downloadDirectoryZip', $this->root->id)
        ->assertHasNoErrors();

    Queue::assertPushed(ZipDirectory::class);

    $archive = DirectoryArchive::firstOrFail();
    expect($archive->requested_by)->toBe($this->owner->id)
        ->and($archive->status)->toBe(ArchiveStatus::Pending);
});

it('hands a built archive only to the person who asked for it', function () {
    $archive = DirectoryArchive::create([
        'directory_id' => $this->root->id,
        'requested_by' => $this->owner->id,
        'status' => ArchiveStatus::Pending,
    ]);

    app(BuildDirectoryArchive::class)->handle($archive);

    $other = User::factory()->create();
    $other->assignRole('admin');
    grant($this->root, $other, AccessLevel::Manage);

    // Note what this proves: $other has MANAGE on the very directory that was
    // archived, so a check written against the directory's policy would let
    // them through. The archive is still not theirs.
    //
    // 404 RATHER THAN 403 SINCE item/reach-oracle-route-binding. {archive}
    // used to be an implicit model binding, so someone else's archive was
    // resolved and then 403'd while a nonexistent id 404'd from the binding
    // -- an existence oracle over every archive id in the instance. The two
    // must agree, and the second half below is what says so; the first half
    // alone passed against the old 403 too.
    $this->actingAs($other)
        ->get(route('directories.archives.download', $archive))
        ->assertNotFound();

    $this->actingAs($other)
        ->get(route('directories.archives.download', ((int) DirectoryArchive::query()->max('id')) + 1))
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->get(route('directories.archives.download', $archive))
        ->assertOk();
});

it('answers 404 for an archive that is not ready yet', function () {
    $archive = DirectoryArchive::create([
        'directory_id' => $this->root->id,
        'requested_by' => $this->owner->id,
        'status' => ArchiveStatus::Building,
    ]);

    $this->actingAs($this->owner)
        ->get(route('directories.archives.download', $archive))
        ->assertNotFound();
});

it('records progress so a poller has something truthful to show', function () {
    storeFile($this->root, $this->owner, 'One.pdf');
    storeFile($this->root, $this->owner, 'Two.pdf');

    $archive = DirectoryArchive::create([
        'directory_id' => $this->root->id,
        'requested_by' => $this->owner->id,
        'status' => ArchiveStatus::Pending,
    ]);

    expect($archive->percentComplete())->toBe(0);

    app(BuildDirectoryArchive::class)->handle($archive);

    $archive->refresh();

    expect($archive->total_files)->toBe(2)
        ->and($archive->completed_files)->toBe(2)
        ->and($archive->percentComplete())->toBe(100)
        ->and($archive->status->isTerminal())->toBeTrue();
});

it('marks the row failed when the job dies, so the poller stops', function () {
    $archive = DirectoryArchive::create([
        'directory_id' => $this->root->id,
        'requested_by' => $this->owner->id,
        'status' => ArchiveStatus::Building,
    ]);

    (new ZipDirectory($archive))->failed(new RuntimeException('disk went away'));

    expect($archive->fresh()->status)->toBe(ArchiveStatus::Failed)
        ->and($archive->fresh()->status->isTerminal())->toBeTrue()
        ->and($archive->fresh()->error)->toContain('disk went away');
});
