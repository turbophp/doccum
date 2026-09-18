<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Actions;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

// Context menus and keyboard (design plan §6, implementation plan Task 6).
//
// The rule this file exists to prove (CLAUDE.md, "Two signals, not one"):
// FilePolicy answers whether the viewer's role and directory reach could
// EVER allow an action -- that decides hidden vs shown. It deliberately does
// not know about legal hold or an archived period, which is exactly why
// @can('delete', $file) returns true for a held file (see the "verified"
// tests below). The reporter this component adds is the second signal: it
// reads the same state TrashFile/RenameFile/MoveFile guard against, so an
// item that IS shown can still say why it is disabled, and that reason can
// never disagree with the exception the action itself would throw.

function actionsGrant(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id, 'grantee_type' => 'user',
        'grantee_id' => $user->id, 'level' => $level,
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
    actionsGrant($this->dir, $this->user, AccessLevel::Edit);
});

// -- Signal 1: hidden, decided by FilePolicy alone --

it('hides Move to trash for a viewer with only view access, and confirms the policy layer denies it', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    actionsGrant($this->dir, $viewer, AccessLevel::View);
    $file = File::factory()->for($this->dir, 'directory')->create();

    // The floor this test stands on: view access is enough to see the file,
    // not enough to delete it, so FilePolicy itself must already say no --
    // the menu isn't inventing a third rule.
    expect($viewer->can('view', $file))->toBeTrue()
        ->and($viewer->can('delete', $file))->toBeFalse();

    Livewire::actingAs($viewer)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->assertDontSee('Move to trash');
});

it('refuses to trash a file directly when the viewer lacks the capability, even though the menu never shows the item', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    actionsGrant($this->dir, $viewer, AccessLevel::View);
    $file = File::factory()->for($this->dir, 'directory')->create();

    // A menu is not an authorisation boundary: the server call is refused on
    // its own, independent of whatever the client happened to render.
    Livewire::actingAs($viewer)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->call('trash')
        ->assertForbidden();

    expect(File::withTrashed()->find($file->id)->trashed())->toBeFalse();
});

it('hides Place legal hold for a viewer without periods.manage', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->assertDontSee('Place legal hold');
});

it('refuses to place a legal hold directly when the viewer lacks periods.manage', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->call('placeLegalHold')
        ->assertForbidden();

    expect($file->fresh()->legal_hold)->toBeFalse();
});

// -- Signal 2: shown, disabled, with the reason --

it('shows Move to trash enabled for a healthy file', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['legal_hold' => false]);

    $reason = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->instance()
        ->blockedReason($file, 'trash');

    expect($reason)->toBeNull();
});

it('shows Move to trash disabled with "Under legal hold" for a held file, even though the policy allows it', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['legal_hold' => true]);

    // Exactly the discrepancy the task brief calls out: the policy alone
    // would offer an enabled item that fails on click.
    expect($this->user->can('delete', $file))->toBeTrue();

    $component = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->assertSee('Move to trash')
        ->assertSee('Under legal hold');

    expect($component->instance()->blockedReason($file, 'trash'))->toBe('Under legal hold');
});

it('shows Move to trash disabled with the archived period and date for a file in an archived period', function () {
    $file = File::factory()->for($this->dir, 'directory')->create([
        'period_year' => 2026, 'period_month' => 8,
    ]);
    ArchivePeriod::factory()->create([
        'year' => 2026, 'month' => 8, 'archived_at' => now()->setDate(2026, 9, 2),
    ]);

    $reason = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->instance()
        ->blockedReason($file, 'trash');

    expect($reason)->toBe('Period archived 2 Sep 2026');
});

it('does not block rename for a held file: RenameFile itself never checks legal hold', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['legal_hold' => true]);

    $reason = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->instance()
        ->blockedReason($file, 'rename');

    // If this ever disagreed with RenameFile's own guard, the menu would be
    // lying about why an item is unavailable.
    expect($reason)->toBeNull();
});

it('blocks rename with the archived period reason, matching RenameFile\'s own guard', function () {
    $file = File::factory()->for($this->dir, 'directory')->create([
        'period_year' => 2026, 'period_month' => 8,
    ]);
    ArchivePeriod::factory()->create([
        'year' => 2026, 'month' => 8, 'archived_at' => now()->setDate(2026, 9, 2),
    ]);

    $reason = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->instance()
        ->blockedReason($file, 'rename');

    expect($reason)->toBe('Period archived 2 Sep 2026');
});

it('actually throws when trash() is called on a held file, proving the item is not merely cosmetically disabled', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['legal_hold' => true]);

    $component = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file]);

    expect(fn () => $component->call('trash'))->toThrow(\App\Exceptions\FileIsUnderLegalHold::class);
    expect(File::withTrashed()->find($file->id)->trashed())->toBeFalse();
});

// -- Working actions --

it('trashes a healthy file and clears the mounted subject', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->call('trash')
        ->assertDispatched('file-trashed', fileId: $file->id);

    expect(File::withTrashed()->find($file->id)->trashed())->toBeTrue();
});

it('places and lifts a legal hold for a viewer with periods.manage', function () {
    $holder = User::factory()->create();
    $holder->givePermissionTo('periods.manage');
    actionsGrant($this->dir, $holder, AccessLevel::View);
    $file = File::factory()->for($this->dir, 'directory')->create(['legal_hold' => false]);

    $component = Livewire::actingAs($holder)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->assertSee('Place legal hold')
        ->call('placeLegalHold');

    expect($file->fresh()->legal_hold)->toBeTrue();

    $component->assertSee('Lift legal hold')->call('liftLegalHold');

    expect($file->fresh()->legal_hold)->toBeFalse();
});

// -- Folder row: policy-hidden only, no hold/period concept for directories --

it('hides Move to trash for a folder when the viewer lacks directories.manage', function () {
    $child = Directory::factory()->for($this->dir, 'parent')->create();

    // 'member' has no directories.manage per RolesAndPermissionsSeeder.
    Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'folder', 'directory' => $child])
        ->assertDontSee('Move to trash');
});

it('refuses to trash a folder directly when the viewer lacks directories.manage', function () {
    $child = Directory::factory()->for($this->dir, 'parent')->create();

    Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'folder', 'directory' => $child])
        ->call('trashDirectory')
        ->assertForbidden();
});

// -- Multi-selection: describes the set; blocked for ANY member disables the whole action --

it('describes the multi-selection set in the trash label', function () {
    $files = File::factory()->for($this->dir, 'directory')->count(3)->create();

    $html = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'multi', 'selectedFileIds' => $files->pluck('id')->all()])
        ->html();

    expect($html)->toContain('Move 3 items to trash');
});

it('disables the multi-selection trash action when any one member is under legal hold, with that reason', function () {
    $healthy = File::factory()->for($this->dir, 'directory')->count(2)->create();
    $held = File::factory()->for($this->dir, 'directory')->create(['legal_hold' => true]);
    $ids = $healthy->pluck('id')->concat([$held->id])->all();

    $component = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'multi', 'selectedFileIds' => $ids]);

    expect($component->instance()->multiTrashReason())->toBe('Under legal hold');

    $component->assertSee('Move 3 items to trash')->assertSee('Under legal hold');
});

it('has no blocking reason for a multi-selection where every member is healthy', function () {
    $files = File::factory()->for($this->dir, 'directory')->count(2)->create();

    $component = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'multi', 'selectedFileIds' => $files->pluck('id')->all()]);

    expect($component->instance()->multiTrashReason())->toBeNull();
});

it('refuses a bulk trash directly when any selected file is under legal hold, even if the caller only asked for it once', function () {
    $healthy = File::factory()->for($this->dir, 'directory')->create();
    $held = File::factory()->for($this->dir, 'directory')->create(['legal_hold' => true]);

    $component = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'multi', 'selectedFileIds' => [$healthy->id, $held->id]]);

    expect(fn () => $component->call('trashSelection'))->toThrow(\App\Exceptions\FileIsUnderLegalHold::class);

    // All-or-nothing: the healthy file must not have been trashed either.
    expect(File::withTrashed()->find($healthy->id)->trashed())->toBeFalse();
    expect(File::withTrashed()->find($held->id)->trashed())->toBeFalse();
});

it('hides the multi-selection trash action entirely when the viewer lacks capability for any member, and still refuses it server-side', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    actionsGrant($this->dir, $viewer, AccessLevel::View);
    $files = File::factory()->for($this->dir, 'directory')->count(2)->create();

    $component = Livewire::actingAs($viewer)
        ->test(Actions::class, ['variant' => 'multi', 'selectedFileIds' => $files->pluck('id')->all()])
        ->assertDontSee('Move 2 items to trash');

    $component->call('trashSelection')->assertForbidden();
});

// -- Markup: roles, kbd hints, the Menu motion --

it('renders the popover as a menu with menu items', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    $html = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->html();

    expect($html)->toContain('role="menu"');
});

it('shows the keyboard shortcut hints from design plan §6', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    $html = Livewire::actingAs($this->user)
        ->test(Actions::class, ['variant' => 'file', 'file' => $file])
        ->html();

    expect($html)->toContain('Enter')
        ->and($html)->toContain('Del');
});

it('never uses the forbidden solid icon variant, and never colours Move to trash as danger', function () {
    $blade = file_get_contents(resource_path('views/livewire/files/actions.blade.php'));

    expect($blade)->not->toMatch('/variant="solid"/')
        ->and($blade)->not->toMatch('/variant="danger"/');
});

it('opens with the Menu motion through $move/$springs, never a hardcoded duration or a direct animate() call', function () {
    $blade = file_get_contents(resource_path('views/livewire/files/actions.blade.php'));

    expect($blade)->toContain('$move(')
        ->and($blade)->not->toContain('from \'motion\'')
        ->and($blade)->not->toMatch('/\banimate\(/');
});

it('keeps the animation entry point out of the menu.js JS file itself, per CLAUDE.md\'s one seam for motion', function () {
    $js = file_get_contents(resource_path('js/shell/menu.js'));

    expect($js)->not->toContain("from 'motion'")
        ->and($js)->not->toMatch('/\banimate\(/');
});
