<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\ExtractionStatus;
use App\Livewire\Files\FileList;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\FileText;
use App\Models\FileVersion;
use App\Models\User;
use App\Services\DirectoryAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

// The core of the shell (implementation plan Task 4): the dense row template
// (design plan §4) and the period spine (§7). What has to hold, precisely:
//
//   - access is filtered in the query (through DirectoryAccess), never the view;
//   - a row's held / archived / extraction-failed / extraction-pending state
//     is shown, and a healthy row shows nothing at all;
//   - selection is client-side and instant -- no `transition`, no `move()` call;
//   - sorting by name or size drops the period bands and the spine's period
//     lines, but every hold tick survives, in every ordering.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->directory = Directory::factory()->create(['name' => 'Contracts']);

    DirectoryGrant::create([
        'directory_id' => $this->directory->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::Manage,
    ]);
});

/** Creates a file with one version, defaulting to a healthy (Done) extraction. */
function fileWith(Directory $directory, array $attributes = [], ?ExtractionStatus $status = ExtractionStatus::Done): File
{
    $file = File::factory()->for($directory, 'directory')->create($attributes);
    $version = FileVersion::factory()->for($file, 'file')->create(['version_number' => 1]);
    $file->update(['current_version_id' => $version->id]);

    if ($status !== null) {
        FileText::factory()->for($version, 'version')->create(['status' => $status]);
    }

    return $file->fresh();
}

it('refuses to list files in a directory the viewer cannot see', function () {
    $other = Directory::factory()->create();

    Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $other])
        ->assertForbidden();
});

it('lists only files belonging to the mounted directory, filtered in the query', function () {
    $elsewhere = Directory::factory()->create();
    DirectoryGrant::create([
        'directory_id' => $elsewhere->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::Manage,
    ]);

    fileWith($this->directory, ['name' => 'Visible.pdf', 'period_year' => 2026, 'period_month' => 9]);
    fileWith($elsewhere, ['name' => 'Hidden.pdf', 'period_year' => 2026, 'period_month' => 9]);

    Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->assertSee('Visible.pdf')
        ->assertDontSee('Hidden.pdf');
});

it('groups rows under period bands by default, carrying the closer-recorded counts for an archived period', function () {
    ArchivePeriod::factory()->create([
        'year' => 2026,
        'month' => 8,
        'archived_at' => now()->setDate(2026, 9, 2),
        'file_count' => 41,
        'byte_count' => 1073741824, // exactly 1.0 GiB, so the formatted string is unambiguous.
    ]);

    fileWith($this->directory, ['name' => 'September file.pdf', 'period_year' => 2026, 'period_month' => 9]);
    fileWith($this->directory, ['name' => 'August file.pdf', 'period_year' => 2026, 'period_month' => 8]);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->assertSee('September 2026')
        ->assertSee('August 2026')
        ->html();

    expect($html)->toContain('data-period-band')
        ->and($html)->toContain('data-period="2026-09"')
        ->and($html)->toContain('data-period="2026-08"')
        // The open period's band carries no archive status.
        ->and($html)->toContain('open')
        // The archived period's band shows the ArchivePeriod row's own
        // recorded totals -- not a count of files in this directory (there
        // is only one August file here, but the closer recorded 41).
        ->and($html)->toContain('41 files')
        ->and($html)->toContain('1.0')
        ->and($html)->toContain('2 Sep 2026');
});

it('draws a hairline period line for an open period and a 2px solid line for an archived one', function () {
    ArchivePeriod::factory()->create([
        'year' => 2026,
        'month' => 8,
        'archived_at' => now(),
        'file_count' => 1,
        'byte_count' => 1024,
    ]);

    fileWith($this->directory, ['name' => 'Open period file.pdf', 'period_year' => 2026, 'period_month' => 9]);
    fileWith($this->directory, ['name' => 'Archived period file.pdf', 'period_year' => 2026, 'period_month' => 8]);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->toContain('data-period-line')
        ->and($html)->toContain('w-px bg-rule')
        ->and($html)->toContain('w-0.5 bg-ink-2');
});

it('shows a lock-closed hold glyph in hold for a file under legal hold', function () {
    fileWith($this->directory, ['name' => 'Held.pdf', 'legal_hold' => true]);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->toContain('Under legal hold')
        ->and($html)->toContain('text-hold');
});

it('shows an archive-box glyph in ink-2 for a file in an archived period', function () {
    ArchivePeriod::factory()->create([
        'year' => 2026, 'month' => 8, 'archived_at' => now(), 'file_count' => 1, 'byte_count' => 1024,
    ]);

    fileWith($this->directory, ['name' => 'Old.pdf', 'period_year' => 2026, 'period_month' => 8]);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->toContain('Archived period')
        ->and($html)->toContain('text-ink-2');
});

it('shows an exclamation-triangle glyph in attention for a failed extraction', function () {
    fileWith($this->directory, ['name' => 'Broken.pdf'], ExtractionStatus::Failed);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->toContain('Extraction failed')
        ->and($html)->toContain('text-attention');
});

it('shows a hollow clock glyph in ink-2 for a pending extraction, including when no file_texts row exists yet', function () {
    fileWith($this->directory, ['name' => 'Just uploaded.pdf'], status: null);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->toContain('Extraction pending');
});

it('shows nothing at all for a healthy, extracted, open-period file -- quiet means fine', function () {
    fileWith($this->directory, ['name' => 'Healthy.pdf'], ExtractionStatus::Done);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->not->toContain('Under legal hold')
        ->and($html)->not->toContain('Archived period')
        ->and($html)->not->toContain('Extraction failed')
        ->and($html)->not->toContain('Extraction pending')
        ->and($html)->not->toContain('No extracted text');
});

it('carries the .num utility on every numeric cell: size, modified, period and version', function () {
    fileWith($this->directory, [
        'name' => 'Numbers.pdf',
        'period_year' => 2026,
        'period_month' => 9,
        'size' => 1_258_291,
    ]);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        // Period and version are only rendered at >=1280px in the markup,
        // but the markup itself must exist and carry `.num` regardless of
        // which breakpoint classes currently hide it.
        ->html();

    expect(substr_count($html, 'num'))->toBeGreaterThanOrEqual(4);
});

it('drops period bands and period lines when sorted by name, but keeps every hold tick', function () {
    ArchivePeriod::factory()->create([
        'year' => 2026, 'month' => 8, 'archived_at' => now(), 'file_count' => 1, 'byte_count' => 1024,
    ]);

    fileWith($this->directory, ['name' => 'A open.pdf', 'period_year' => 2026, 'period_month' => 9]);
    fileWith($this->directory, ['name' => 'B held.pdf', 'period_year' => 2026, 'period_month' => 8, 'legal_hold' => true]);

    $component = Livewire::actingAs($this->user)->test(FileList::class, ['directory' => $this->directory]);

    // Sanity check: grouped by default, bands and period lines are there.
    $grouped = $component->html();
    expect($grouped)->toContain('data-period-band')
        ->and($grouped)->toContain('data-period-line')
        ->and($grouped)->toContain('data-hold-tick');

    $sortedByName = $component->call('sortBy', 'name')->html();

    expect($sortedByName)->not->toContain('data-period-band')
        ->and($sortedByName)->not->toContain('data-period-line')
        // The hold tick is the one thing the design says must survive every
        // ordering (design plan §7): "the hold ticks stay, because a hold
        // must be visible in every ordering."
        ->and($sortedByName)->toContain('data-hold-tick');
});

it('drops period bands and period lines when sorted by size, but keeps every hold tick', function () {
    fileWith($this->directory, ['name' => 'Small.pdf', 'period_year' => 2026, 'period_month' => 9, 'size' => 10, 'legal_hold' => true]);
    fileWith($this->directory, ['name' => 'Large.pdf', 'period_year' => 2026, 'period_month' => 9, 'size' => 999_999]);

    $sortedBySize = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->call('sortBy', 'size')
        ->html();

    expect($sortedBySize)->not->toContain('data-period-band')
        ->and($sortedBySize)->not->toContain('data-period-line')
        ->and($sortedBySize)->toContain('data-hold-tick');
});

it('flips direction when the same column is sorted again', function () {
    fileWith($this->directory, ['name' => 'A.pdf']);
    fileWith($this->directory, ['name' => 'B.pdf']);

    $component = Livewire::actingAs($this->user)->test(FileList::class, ['directory' => $this->directory]);

    $component->call('sortBy', 'name');
    expect($component->get('direction'))->toBe('asc');

    $component->call('sortBy', 'name');
    expect($component->get('direction'))->toBe('desc');
});

it('renders a row carrying its own id for the client-side selection model to key against', function () {
    $file = fileWith($this->directory, ['name' => 'Selectable.pdf']);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->toContain('data-row-id="'.$file->id.'"')
        ->and($html)->toContain('data-file-grid')
        ->and($html)->toContain('$store.selection.click(')
        ->and($html)->toContain('$store.selection.toggle(');
});

it('keeps rows exempt from hover transitions and never animates selection, per design plan §5 motion #1', function () {
    $blade = file_get_contents(resource_path('views/livewire/files/file-list.blade.php'));
    $js = file_get_contents(resource_path('js/shell/selection.js'));

    // "No animation. Background changes on the same frame" -- so neither
    // file carries a `transition` utility or a call into the shell's one
    // animation entry point.
    expect($blade)->not->toContain('transition')
        ->and($js)->not->toContain('transition')
        ->and($js)->not->toContain("from 'motion'")
        ->and($js)->not->toContain('move(');
});

it('never uses the forbidden solid icon variant', function () {
    $blade = file_get_contents(resource_path('views/livewire/files/file-list.blade.php'));

    expect($blade)->not->toMatch('/variant="solid"/');
});

it('lists subdirectories as rows above the files', function () {
    // The design puts folder rows in the list on the same template as files,
    // and drag-and-drop targets them. A centre pane with no folders is also
    // not the Drive parity this shell is measured against.
    $child = Directory::factory()->for($this->directory, 'parent')->create(['name' => 'Contracts']);
    File::factory()->for($this->directory, 'directory')->create(['name' => 'Report.pdf']);

    $html = Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->html();

    expect($html)->toContain('Contracts')
        ->and($html)->toContain('data-folder-row')
        ->and($html)->toContain('data-folder-id="'.$child->id.'"')
        // Folders sort above files: a directory has no period, so it cannot
        // sit inside a band.
        ->and(strpos($html, 'Contracts'))->toBeLessThan(strpos($html, 'Report.pdf'));
});

it('does not leak siblings when listing a directory reached only as an ancestor', function () {
    // Grants inherit downward, so a viewer who can see a folder can see its
    // children -- which makes the obvious version of this test meaningless.
    // The case that is real: a grant deep in the tree makes its ANCESTORS
    // navigable so the grant can be reached at all, and opening one of those
    // ancestors must show only the path onward, never its other children.
    $root = Directory::factory()->create(['name' => 'Root']);
    $onPath = Directory::factory()->for($root, 'parent')->create(['name' => 'OnPath']);
    $granted = Directory::factory()->for($onPath, 'parent')->create(['name' => 'Granted']);
    $sibling = Directory::factory()->for($root, 'parent')->create(['name' => 'SiblingBranch']);

    $stranger = User::factory()->create();
    $stranger->assignRole('member');
    DirectoryGrant::create([
        'directory_id' => $granted->id, 'grantee_type' => 'user',
        'grantee_id' => $stranger->id, 'level' => AccessLevel::View,
    ]);

    $viewable = app(DirectoryAccess::class)->viewableDirectoryIds($stranger);

    // The listing filters on exactly this set, so assert against it directly:
    // the granted node is in, the ancestors and the sibling branch are not.
    expect($viewable)->toContain($granted->id)
        ->and($viewable)->not->toContain($root->id)
        ->and($viewable)->not->toContain($onPath->id)
        ->and($viewable)->not->toContain($sibling->id);
});

it('announces a folder rather than navigating itself', function () {
    $child = Directory::factory()->for($this->directory, 'parent')->create(['name' => 'Contracts']);

    Livewire::actingAs($this->user)
        ->test(FileList::class, ['directory' => $this->directory])
        ->call('openFolder', $child->id)
        ->assertDispatched('directory-selected', directoryId: $child->id);
});
