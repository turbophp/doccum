<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Tree;
use App\Models\Directory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

// Task 3 (implementation plan): the hand-built tree pane. Flux ships no tree
// component, so this is a recursive Blade partial fed by a Livewire component
// that lazily queries one level of children at a time, filtered exclusively
// through DirectoryAccess::viewableDirectoryIds() -- never in the view.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

// Reuses the global grant() helper already declared in DirectoryAccessTest --
// Pest loads every test file's top-level functions into one global scope, so
// a second declaration of the same name is a fatal redeclaration, not a
// harmless shadow.
it('never lists a directory the viewer cannot reach, at the root or any deeper level', function () {
    $root = Directory::factory()->create(['name' => 'Root']);
    $branch = Directory::factory()->create(['parent_id' => $root->id, 'name' => 'Branch']);
    $deep = Directory::factory()->create(['parent_id' => $branch->id, 'name' => 'Deep']);

    // A sibling root the viewer has no grant on, and a sibling branch under
    // the same ancestor the viewer IS allowed to see. Both must stay
    // invisible: this is the subtle half of the rule that a plain
    // `whereIn(viewableDirectoryIds())` gets wrong, because that resolver
    // only expands a grant DOWN into descendants, never back UP to ancestors.
    $siblingRoot = Directory::factory()->create(['name' => 'SiblingRoot']);
    $siblingBranch = Directory::factory()->create(['parent_id' => $root->id, 'name' => 'SiblingBranch']);

    grant($deep, $this->user, AccessLevel::View);

    $component = Livewire::actingAs($this->user)->test(Tree::class);

    // Proof at the query level: only the ancestor chain down to the grant is
    // ever fetched, at every level, never a sibling.
    expect($component->instance()->childrenOf(null)->pluck('name')->all())->toBe(['Root'])
        ->and($component->instance()->childrenOf($root->id)->pluck('name')->all())->toBe(['Branch'])
        ->and($component->instance()->childrenOf($branch->id)->pluck('name')->all())->toBe(['Deep']);

    // Proof at the rendered-HTML level: even after expanding every ancestor
    // node on the path -- the point at which a view-level leak would show up
    // -- the sibling branches never appear.
    $html = $component
        ->call('toggle', $root->id)
        ->call('toggle', $branch->id)
        ->html(true);

    expect($html)->toContain('Root')
        ->and($html)->toContain('Branch')
        ->and($html)->toContain('Deep')
        ->and($html)->not->toContain('SiblingRoot')
        ->and($html)->not->toContain('SiblingBranch');
});

it('shows nothing at all to a viewer with no grants anywhere', function () {
    Directory::factory()->create(['name' => 'Untouchable']);

    Livewire::actingAs($this->user)->test(Tree::class)
        ->assertDontSee('Untouchable');
});

it('persists expansion state per viewer across page loads', function () {
    $directory = Directory::factory()->create(['name' => 'Contracts']);
    grant($directory, $this->user, AccessLevel::View);

    Livewire::actingAs($this->user)->test(Tree::class)
        ->call('toggle', $directory->id);

    // A brand new component instance is exactly what a fresh page load does:
    // a new mount(), nothing carried over except what was actually persisted.
    Livewire::actingAs($this->user)->test(Tree::class)
        ->assertSet('expanded', [$directory->id => true]);
});

it('keeps expansion state private to the viewer who set it', function () {
    $directory = Directory::factory()->create(['name' => 'Contracts']);
    grant($directory, $this->user, AccessLevel::View);
    grant($directory, $other = User::factory()->create(['username' => 'other']), AccessLevel::View);
    $other->assignRole('member');

    Livewire::actingAs($this->user)->test(Tree::class)
        ->call('toggle', $directory->id);

    Livewire::actingAs($other)->test(Tree::class)
        ->assertSet('expanded', []);
});

it('pins the home directory above the shared tree, labelled with the username', function () {
    $user = User::factory()->withHome()->create(['username' => 'ada']);
    $user->assignRole('member');

    $shared = Directory::factory()->create(['name' => 'Finance']);
    grant($shared, $user, AccessLevel::View);

    $component = Livewire::actingAs($user)->test(Tree::class)
        ->assertSee('ada')
        ->assertSee('Finance');

    $html = $component->html(true);

    expect(strpos($html, 'ada'))->toBeLessThan(strpos($html, 'Finance'));
});

it('does not render a home pin for a viewer with no home directory', function () {
    // The default `member` factory user in beforeEach has no home directory.
    Livewire::actingAs($this->user)->test(Tree::class)
        ->assertOk();
});

it('emits a directory-selected event on selection, without coupling to the list component', function () {
    $directory = Directory::factory()->create(['name' => 'Contracts']);
    grant($directory, $this->user, AccessLevel::View);

    Livewire::actingAs($this->user)->test(Tree::class)
        ->call('select', $directory->id)
        ->assertDispatched('directory-selected', directoryId: $directory->id);
});
