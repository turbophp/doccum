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
