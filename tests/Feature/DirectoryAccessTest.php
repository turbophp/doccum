<?php

declare(strict_types=1);

use App\Actions\Directories\MoveDirectory;
use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use App\Services\DirectoryAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->root = Directory::factory()->create();
    $this->mid = Directory::factory()->for($this->root, 'parent')->create();
    $this->leaf = Directory::factory()->for($this->mid, 'parent')->create();
    $this->elsewhere = Directory::factory()->create();
    $this->user = User::factory()->create();
});

function grant(Directory $dir, $grantee, AccessLevel $level): DirectoryGrant
{
    return DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => $grantee instanceof Role ? 'role' : 'user',
        'grantee_id' => $grantee->id,
        'level' => $level,
    ]);
}

it('returns null where nothing is granted', function () {
    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBeNull();
});

it('inherits a grant down the whole subtree', function () {
    grant($this->root, $this->user, AccessLevel::Edit);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit)
        ->and(app(DirectoryAccess::class)->levelFor($this->user, $this->mid))->toBe(AccessLevel::Edit);
});

it('does not leak a grant sideways', function () {
    grant($this->root, $this->user, AccessLevel::Manage);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->elsewhere))->toBeNull();
});

it('takes the highest level among ancestors', function () {
    grant($this->root, $this->user, AccessLevel::View);
    grant($this->mid, $this->user, AccessLevel::Manage);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Manage);
});

it('never lowers access from a narrower grant', function () {
    grant($this->root, $this->user, AccessLevel::Manage);
    grant($this->leaf, $this->user, AccessLevel::View);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Manage);
});

it('resolves grants made to a role the user holds', function () {
    $role = Role::findByName('member');
    $this->user->assignRole($role);
    grant($this->root, $role, AccessLevel::Edit);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);
});

it('combines user and role grants, taking the highest', function () {
    $role = Role::findByName('member');
    $this->user->assignRole($role);
    grant($this->root, $role, AccessLevel::View);
    grant($this->mid, $this->user, AccessLevel::Edit);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);
});

it('gives manage everywhere to a holder of directories.view-all', function () {
    $this->user->assignRole('admin');

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->elsewhere))->toBe(AccessLevel::Manage);
});

it('answers can() against the required level', function () {
    grant($this->root, $this->user, AccessLevel::Edit);
    $access = app(DirectoryAccess::class);

    expect($access->can($this->user, $this->leaf, AccessLevel::View))->toBeTrue()
        ->and($access->can($this->user, $this->leaf, AccessLevel::Edit))->toBeTrue()
        ->and($access->can($this->user, $this->leaf, AccessLevel::Manage))->toBeFalse();
});

it('expands viewable ids to whole subtrees', function () {
    grant($this->mid, $this->user, AccessLevel::View);

    $ids = app(DirectoryAccess::class)->viewableDirectoryIds($this->user);

    expect($ids)->toContain($this->mid->id, $this->leaf->id)
        ->and($ids)->not->toContain($this->root->id)
        ->and($ids)->not->toContain($this->elsewhere->id);
});

it('returns every directory id for an admin', function () {
    $this->user->assignRole('admin');

    expect(app(DirectoryAccess::class)->viewableDirectoryIds($this->user))
        ->toHaveCount(Directory::count());
});

it('returns an empty list for a user with no grants', function () {
    expect(app(DirectoryAccess::class)->viewableDirectoryIds($this->user))->toBe([]);
});

it('sees a grant written after an earlier resolution', function () {
    $access = app(DirectoryAccess::class);

    expect($access->levelFor($this->user, $this->leaf))->toBeNull();

    grant($this->root, $this->user, AccessLevel::Edit);

    expect($access->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);
});

it('sees a grant revoked after an earlier resolution', function () {
    $access = app(DirectoryAccess::class);
    $granted = grant($this->root, $this->user, AccessLevel::Edit);

    expect($access->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);

    $granted->delete();

    expect($access->levelFor($this->user, $this->leaf))->toBeNull();
});

it('sees a level raised after an earlier resolution', function () {
    $access = app(DirectoryAccess::class);
    grant($this->root, $this->user, AccessLevel::View);

    expect($access->can($this->user, $this->leaf, AccessLevel::Manage))->toBeFalse();

    DirectoryGrant::query()->delete();
    grant($this->root, $this->user, AccessLevel::Manage);

    expect($access->can($this->user, $this->leaf, AccessLevel::Manage))->toBeTrue();
});

it('sees a directory moved out of a granted subtree', function () {
    $access = app(DirectoryAccess::class);
    grant($this->root, $this->user, AccessLevel::Edit);

    expect($access->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);

    app(MoveDirectory::class)->handle($this->leaf->fresh(), $this->elsewhere->fresh());

    expect($access->levelFor($this->user, $this->leaf->fresh()))->toBeNull();
});

it('excludes a live descendant of a trashed directory even when the grant is on a live ancestor above it', function () {
    grant($this->root, $this->user, AccessLevel::Edit);
    $this->mid->delete();

    $access = app(DirectoryAccess::class);

    expect($access->levelFor($this->user, $this->leaf))->toBeNull()
        ->and($access->viewableDirectoryIds($this->user))->not->toContain($this->leaf->id);
});

it('excludes a live descendant of a trashed directory even when the grant sits on the trashed directory itself', function () {
    // Previously inconsistent with the case above: a grant made directly on
    // the directory being trashed took a completely different path through
    // resolveViewable() (the trashed row simply dropped out of the path
    // lookup) than a grant made on a live ancestor above it. Both must hide
    // the same live descendant the same way. See issue #49.
    grant($this->mid, $this->user, AccessLevel::Manage);
    $liveGrandchild = Directory::factory()->for($this->leaf, 'parent')->create();
    $this->mid->delete();

    $access = app(DirectoryAccess::class);

    expect($access->levelFor($this->user, $liveGrandchild))->toBeNull()
        ->and($access->viewableDirectoryIds($this->user))->not->toContain($liveGrandchild->id);
});

it('still resolves a grant made directly on the directory being trashed, checked against itself', function () {
    // The manage check RestoreDirectory's caller needs to authorise restoring
    // D depends on resolving access ON D while D is trashed -- the defensive
    // rule above must not defeat that by treating D's own trashed state as
    // disqualifying.
    grant($this->mid, $this->user, AccessLevel::Manage);
    $this->mid->delete();

    $trashedMid = Directory::withTrashed()->findOrFail($this->mid->id);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $trashedMid))->toBe(AccessLevel::Manage);
});

// --- reach roots (item/files-three-pane, issue #104/#99) -------------------

it('treats a directly-granted nested directory as a reach root when its own parent is not viewable', function () {
    grant($this->mid, $this->user, AccessLevel::View);

    $roots = app(DirectoryAccess::class)->reachRootIds($this->user);

    // $this->mid's parent ($this->root) carries no grant at all -- not
    // viewable -- so $mid is the top of this viewer's reach.
    expect($roots)->toContain($this->mid->id)
        // $this->leaf is viewable too (inherited from $mid), but its
        // parent ($mid) IS viewable, so leaf is a descendant, not a root.
        ->and($roots)->not->toContain($this->leaf->id)
        // $this->root itself is not viewable at all -- it must not appear
        // just because a directory beneath it does.
        ->and($roots)->not->toContain($this->root->id);
});

it('treats a directory with no viewable parent, including a genuinely root-level one, as a reach root', function () {
    grant($this->root, $this->user, AccessLevel::Edit);

    $roots = app(DirectoryAccess::class)->reachRootIds($this->user);

    expect($roots)->toBe([$this->root->id]);
});

it('resolves every filesystem root, and nothing nested, for a holder of directories.view-all', function () {
    $this->user->assignRole('admin');

    $roots = app(DirectoryAccess::class)->reachRootIds($this->user);

    // Every directory is viewable to this user, so a reach root here is
    // exactly a TRUE filesystem root: $this->root and $this->elsewhere,
    // both parent_id IS NULL -- never $this->mid or $this->leaf, whose
    // parents are viewable too.
    expect($roots)->toEqualCanonicalizing([$this->root->id, $this->elsewhere->id]);
});

it('returns an empty reach for a user with no grants at all', function () {
    expect(app(DirectoryAccess::class)->reachRootIds($this->user))->toBe([]);
});

it('nests every viewable descendant under its reach root in reachTree()', function () {
    grant($this->mid, $this->user, AccessLevel::Manage);

    $tree = app(DirectoryAccess::class)->reachTree($this->user);

    expect($tree->pluck('id')->all())->toBe([$this->mid->id])
        ->and($tree->first()->children->pluck('id')->all())->toBe([$this->leaf->id]);
});

it('caches a denial instead of re-querying it', function () {
    $access = app(DirectoryAccess::class);

    // Warm it: this one legitimately queries.
    expect($access->levelFor($this->user, $this->leaf))->toBeNull();

    DB::enableQueryLog();
    $access->levelFor($this->user, $this->leaf);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'directory_access')))->toBeEmpty();
});
