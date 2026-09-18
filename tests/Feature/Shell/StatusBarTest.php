<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Shell\StatusBar;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// Task 8 (implementation plan), the status-bar half only -- the topbar was
// already built independently on main. Design plan §3 and §6: the current
// folder's counts, replaced by the selection count the moment anything is
// selected, replaced in turn by the sentence a blocked drag or menu item
// explains itself with.
//
// Reuses the global `grant()` helper declared in DirectoryAccessTest.php --
// Pest loads every test file's top-level functions into one global scope
// (see TreeTest.php's own note), so redeclaring it here would be a fatal
// redeclaration, not a harmless shadow.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->directory = Directory::factory()->create(['name' => 'Contracts']);
    grant($this->directory, $this->user, AccessLevel::Manage);
});

/**
 * Collapses whitespace so a numeral and its label can be asserted as one
 * phrase regardless of the newlines Blade's own formatting puts between
 * them -- a real browser collapses that whitespace when it renders text, so
 * this mirrors what the reader actually sees rather than the raw markup.
 */
function collapsed(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}

it('shows the current folder\'s item count and total size, both carrying .num', function () {
    File::factory()->for($this->directory, 'directory')->create(['size' => 1_000_000]);
    File::factory()->for($this->directory, 'directory')->create(['size' => 258_291]);

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    expect($html)->toContain('2')
        ->and($html)->toContain('files')
        ->and($html)->toContain('1.2')
        ->and($html)->toContain('MB')
        ->and(substr_count($html, 'num'))->toBeGreaterThanOrEqual(2);
});

it('shows the singular "file" for exactly one file', function () {
    File::factory()->for($this->directory, 'directory')->create();

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    expect(collapsed($html))->toContain('1 file')
        ->and(collapsed($html))->not->toContain('1 files');
});

it('shows nothing extra for a folder with no held or archived files -- quiet means fine', function () {
    File::factory()->for($this->directory, 'directory')->create();

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    expect($html)->not->toContain('under hold')
        ->and($html)->not->toContain('in archived period');
});

it('shows the held count in hold colour, counting only files under legal hold', function () {
    File::factory()->for($this->directory, 'directory')->create(['legal_hold' => true]);
    File::factory()->for($this->directory, 'directory')->create(['legal_hold' => false]);

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    expect($html)->toContain('under hold')
        ->and($html)->toContain('text-hold');

    // Only the held numeral carries the colour -- everything else in the bar
    // is ink-2 on chrome (design plan §1: "red means legal hold and never
    // danger").
    expect($html)->toContain('<span class="num text-hold">1</span>');
});

it('counts files whose period is covered by an archived ArchivePeriod', function () {
    ArchivePeriod::factory()->create([
        'year' => 2026, 'month' => 8, 'archived_at' => now(), 'file_count' => 1, 'byte_count' => 1024,
    ]);

    File::factory()->for($this->directory, 'directory')->create(['period_year' => 2026, 'period_month' => 8]);
    File::factory()->for($this->directory, 'directory')->create(['period_year' => 2026, 'period_month' => 9]);

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    expect($html)->toContain('1')
        ->and($html)->toContain('in archived period');
});

it('does not include files from a directory the viewer was never granted, even once told its id', function () {
    // No grant on this one at all -- simulates the status bar being pointed
    // at a directory it should never have been (a stale event, a tampered
    // query string): the counts must not leak that folder's real contents.
    $forbidden = Directory::factory()->create(['name' => 'Forbidden']);
    File::factory()->for($forbidden, 'directory')->create(['size' => 5_000_000, 'legal_hold' => true]);

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class)
        ->dispatch('directory-selected', directoryId: $forbidden->id)
        ->html();

    // All-zero, indistinguishable from a genuinely empty, reachable folder --
    // never the real count, and never a count that reveals the held file.
    expect(collapsed($html))->toContain('0 files')
        ->and($html)->not->toContain('under hold')
        ->and($html)->not->toContain('5.0')
        ->and($html)->not->toContain('MB');
});

it('does not leak a sibling directory\'s files into the granted folder\'s counts', function () {
    $elsewhere = Directory::factory()->create();
    File::factory()->for($elsewhere, 'directory')->create(['size' => 9_999_999]);
    File::factory()->for($this->directory, 'directory')->create(['size' => 10]);

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    expect(collapsed($html))->toContain('1 file')
        ->and(collapsed($html))->not->toContain('2 files');
});

it('updates the current folder when directory-selected is dispatched, the same event the tree and list use', function () {
    $other = Directory::factory()->create(['name' => 'Invoices']);
    grant($other, $this->user, AccessLevel::View);
    File::factory()->for($other, 'directory')->count(3)->create();

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->dispatch('directory-selected', directoryId: $other->id)
        ->html();

    expect(collapsed($html))->toContain('3 files');
});

it('renders the selection summary from the client-side store, toggled against the folder counts, never a Livewire round trip', function () {
    File::factory()->for($this->directory, 'directory')->create();

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    expect($html)->toContain('data-selection-count')
        ->and($html)->toContain('$store.selection.ids.length')
        ->and($html)->toContain('data-folder-counts')
        ->and($html)->toContain('$store.selection.anySelected()');
});

it('exposes a listener that shows a blocked action\'s reason, replacing the rest of the bar', function () {
    File::factory()->for($this->directory, 'directory')->create();

    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->dispatch('action-blocked', reason: 'Under legal hold')
        ->html();

    expect($html)->toContain('Under legal hold')
        ->and($html)->not->toContain('data-folder-counts')
        ->and($html)->not->toContain('data-selection-count');
});

it('does not interpret the blocked reason -- it displays whatever string it is given', function () {
    $html = Livewire::actingAs($this->user)
        ->test(StatusBar::class)
        ->dispatch('action-blocked', reason: 'Period archived 2 Sep 2026')
        ->html();

    expect($html)->toContain('Period archived 2 Sep 2026');
});

it('clears the blocked reason on the next interaction', function () {
    File::factory()->for($this->directory, 'directory')->create();

    $component = Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->dispatch('action-blocked', reason: 'Under legal hold');

    expect($component->html())->toContain('Under legal hold');

    // Any subsequent interaction -- here, the same navigation event the tree
    // and list already send -- starts clean rather than pinning a stale
    // refusal on screen.
    $html = $component->dispatch('directory-selected', directoryId: $this->directory->id)->html();

    expect($html)->not->toContain('Under legal hold')
        ->and($html)->toContain('data-folder-counts');
});

it('costs the same number of queries whether the folder holds 2 files or 50 -- never a query per row', function () {
    File::factory()->for($this->directory, 'directory')->count(2)->create();

    // A throwaway render first, so permission/role caching that Spatie keeps
    // in memory for the rest of the process has already happened before
    // either measurement below -- otherwise the first measurement carries
    // one-off query cost the second one does not, for reasons having
    // nothing to do with the number of files in the folder.
    Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    DB::enableQueryLog();
    DB::flushQueryLog();

    Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    $withFewFiles = count(DB::getQueryLog());

    File::factory()->for($this->directory, 'directory')->count(48)->create();

    DB::flushQueryLog();

    Livewire::actingAs($this->user)
        ->test(StatusBar::class, ['directoryId' => (string) $this->directory->id])
        ->html();

    $withManyFiles = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($withManyFiles)->toBe($withFewFiles);
});

it('never uses the forbidden solid icon variant', function () {
    $blade = file_get_contents(resource_path('views/livewire/shell/status-bar.blade.php'));

    expect($blade)->not->toMatch('/variant="solid"/');
});
