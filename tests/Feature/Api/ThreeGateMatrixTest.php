<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\ApiTokenAbility;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\FileText;
use App\Models\FileVersion;
use App\Models\PropertyDefinition;
use App\Models\User;
use App\Services\DirectoryAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/**
 * item/api-content (issue #23), doneWhen clause 1: "Per-endpoint three-gate
 * matrix: a missing ability is 403 despite the permission, a denied ACL is
 * 404 or 403 [settled: 404], and revoking the owner's role narrows an
 * existing token on the next request; ... no re-issue needed."
 *
 * Every route registered in routes/api.php gets its own block below, in the
 * same order as that file (PART 1 covers the six endpoints whose gate (b)
 * and (c) shapes differ enough to need worked examples; PART 2 covers the
 * rest, grouped by which of those same shapes they happen to share). Three
 * shapes recur, and each is named where it first appears rather than
 * re-explained every time:
 *
 *   - Full three gates: an ability, a Spatie permission, and an ACL level
 *     all independently gate the route (POST /directories, DELETE
 *     /files/{id}, PATCH /directories/{id} moving, DELETE /directories/{id},
 *     POST /trash/file/{id}/restore).
 *   - ACL-only, gate (c) N/A: the route's Policy method asks
 *     DirectoryAccess alone, with no Spatie permission to ever revoke
 *     (DirectoryPolicy::view()/update(), FilePolicy::view()/update()/
 *     move()) -- true for the two directory/file "read a thing" families,
 *     every rename, and file moves specifically (FilePolicy::move() has NO
 *     permission clause at all, unlike DirectoryPolicy::move()). Documented
 *     as N/A with a real test asserting the ACL-only route keeps answering
 *     after every role is stripped, not left as a silent gap.
 *   - Listing filtered by ACL, no authorize() call: GET /directories,
 *     GET /trash, GET /search all filter their own query against
 *     DirectoryAccess rather than 404ing or calling $this->authorize() at
 *     all (matching their Livewire equivalents, Browser::render() and
 *     Trash\Index::render()). Gate (b) is "a hidden row never appears",
 *     not a 404; gate (c) still applies through a ROLE-GRANTED
 *     DirectoryGrant, which is the one honest way to demonstrate "revoking
 *     the owner's role narrows" a route with no Spatie permission of its
 *     own to revoke.
 *   - Neither gate applies at all: GET /property-definitions has no ACL
 *     dimension (it is instance-wide metadata, see
 *     PropertyDefinitionController's own docblock) and no Policy call,
 *     so BOTH (b) and (c) are N/A, documented as such rather than
 *     invented.
 *
 * Gate (a) for every one of these is ALSO covered by the generic sweep
 * below ("403s every /api/v1 route..."), which reads the abilities
 * straight off Route::getRoutes() rather than a hand-typed list; the
 * per-endpoint blocks additionally assert gate (a) themselves so each
 * block is a self-contained proof of its own row, not dependent on the
 * sweep to be legible.
 *
 * "The next request, no re-issue needed" is tested by re-establishing
 * Sanctum::actingAs() on a FRESHLY-QUERIED user model after the role
 * mutation, never the same in-memory $user the test already holds --
 * tests/Feature/RolesAndPermissionsTest.php already needs ->fresh() for
 * the identical reason (a loaded `roles` relation does not un-cache
 * itself), and a REAL second HTTP request would resolve the token's owner
 * fresh from the database regardless, through Sanctum's own guard. Reusing
 * the stale object would prove nothing about the API and everything about
 * PHP object caching.
 */
beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Revokes $user's role and re-authenticates as the SAME token abilities,
 * simulating "the next request" -- no re-issue needed, same as the
 * doneWhen's own words.
 *
 * DirectoryAccess is a singleton whose memoisation is flushed by a
 * DirectoryGrant or Directory write (see its own docblock) but has no
 * listener on Spatie's role tables, which is a different concern
 * entirely. A real second HTTP request gets a fresh instance of that
 * singleton for free; a Pest test issuing two simulated requests through
 * the SAME booted application does not (Laravel's TestCase reuses one
 * application across every call() in a test method) -- so this flush()
 * is what makes the SIMULATION match a real second request, for a
 * role-based grant specifically (App\Services\DirectoryAccess::
 * grantsFor()'s role half). It changes nothing for a grant made directly
 * to the user, which every OTHER "narrows" test below uses -- included
 * everywhere anyway, since a test that only works by accident of which
 * grant shape it happens to use is not a test to trust.
 *
 * ->fresh() on $user for the same reason
 * tests/Feature/RolesAndPermissionsTest.php already needs it: a loaded
 * `roles` relation does not un-cache itself just because a row changed
 * underneath it.
 */
function actingAsAfterRoleRevoked(User $user, string $role, array $abilities): void
{
    $user->removeRole($role);
    app(DirectoryAccess::class)->flush();

    Sanctum::actingAs($user->fresh(), $abilities);
}

/**
 * A disposable role holding exactly the given permissions -- for
 * directories.manage specifically, which MEMBER_PERMISSIONS does not
 * carry (RolesAndPermissionsSeeder), so 'member' cannot be reused for it
 * the way every other "narrows" test above reuses it. Deliberately never
 * 'admin': that role also carries directories.view-all, which makes
 * DirectoryAccess::resolve() answer Manage unconditionally and would
 * leave nothing for the ACL half of these tests to deny.
 */
function roleWithPermissions(string $name, array $permissions): Role
{
    $role = Role::findOrCreate($name, 'web');
    $role->givePermissionTo($permissions);

    return $role;
}

// --- generic gate (a): every registered route, read off the router --------

it('403s every /api/v1 route when the token lacks the ability it declares, even for an admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin'); // holds every Spatie permission there is.

    $routes = collect(Route::getRoutes())->filter(
        fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'),
    );

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $abilities = collect($route->gatherMiddleware())
            ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'ability:'))
            ->map(fn (string $middleware): string => substr($middleware, strlen('ability:')))
            ->all();

        // Every route in routes/api.php carries at least one `ability:`
        // middleware; a route with none would be a real gap this test
        // should fail loudly on, not silently skip.
        expect($abilities)->not->toBeEmpty("{$route->uri()} declares no ability middleware at all.");

        // A token holding every ability EXCEPT the ones this route
        // declares -- an admin, so the permission and ACL gates could
        // never be the reason for a refusal here.
        $missing = array_values(array_diff(ApiTokenAbility::values(), $abilities));
        Sanctum::actingAs($admin, $missing);

        // {type} carries a whereIn('type', ['file', 'directory']) route
        // constraint (see routes/api.php's trash.restore route) -- any
        // other value fails to MATCH the route at all, which 404s before
        // any middleware runs and would silently pass this test for the
        // wrong reason. Every other placeholder is a bare id.
        $uri = preg_replace('/\{type\}/', 'file', $route->uri());
        $uri = preg_replace('/\{[^}]+\}/', '999999', $uri);
        $method = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');

        $response = $this->json($method, '/'.$uri);

        expect($response->status())->toBe(
            403,
            "{$method} /{$uri} answered {$response->status()}, not 403, for a token missing [".implode(',', $abilities).'].',
        );
    }
});

// ============================================================================
// PART 1 -- worked examples of each recurring shape.
// ============================================================================

// --- directories:write -- POST /api/v1/directories -------------------------
// Shape: full three gates (permission directories.create, ACL edit).

describe('POST /api/v1/directories', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member'); // MEMBER_PERMISSIONS carries directories.create.

        $this->parent = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->parent->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $this->outside = Directory::factory()->create(); // no grant at all
    });

    it('403s a token missing directories:write, despite the permission and the ACL both being fine', function () {
        Sanctum::actingAs($this->owner, ['directories:read']);

        $this->postJson('/api/v1/directories', ['parent_id' => $this->parent->id, 'name' => 'Reports'])
            ->assertForbidden();
    });

    it('404s a parent wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['directories:write']);

        $this->postJson('/api/v1/directories', ['parent_id' => $this->outside->id, 'name' => 'Reports'])
            ->assertNotFound();
    });

    it('narrows on the next request when the owner\'s role is revoked, no re-issue needed', function () {
        Sanctum::actingAs($this->owner, ['directories:write']);

        $this->postJson('/api/v1/directories', ['parent_id' => $this->parent->id, 'name' => 'First'])
            ->assertCreated();

        actingAsAfterRoleRevoked($this->owner, 'member', ['directories:write']);

        $this->postJson('/api/v1/directories', ['parent_id' => $this->parent->id, 'name' => 'Second'])
            ->assertForbidden();
    });
});

// --- directories:read -- GET /api/v1/directories/{id} ----------------------
// Shape: ACL-only, gate (c) N/A (DirectoryPolicy::view() is ACL alone).

describe('GET /api/v1/directories/{id}', function () {
    beforeEach(function () {
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('member');

        $this->visible = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->visible->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->viewer->id,
            'level' => AccessLevel::View,
        ]);

        $this->hidden = Directory::factory()->create();
    });

    it('403s a token missing directories:read, despite ACL reach', function () {
        Sanctum::actingAs($this->viewer, ['files:read']);

        $this->getJson("/api/v1/directories/{$this->visible->id}")->assertForbidden();
    });

    it('404s a directory wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->viewer, ['directories:read']);

        $this->getJson("/api/v1/directories/{$this->hidden->id}")->assertNotFound();
    });

    it('has no Spatie permission to narrow for a pure view -- DirectoryPolicy::view() is ACL-only', function () {
        // Documented rather than faked (CLAUDE.md, decision/0080): removing
        // EVERY role from the viewer still leaves them able to view a
        // directory a grant names directly, because view() asks
        // DirectoryAccess alone. This is the honest shape of gate (c) for
        // this one route, not a gap in the test.
        $this->viewer->syncRoles([]);
        Sanctum::actingAs($this->viewer->fresh(), ['directories:read']);

        $this->getJson("/api/v1/directories/{$this->visible->id}")->assertOk();
    });
});

// --- files:delete -- DELETE /api/v1/files/{id} -----------------------------
// Shape: full three gates (permission files.delete, ACL edit).

describe('DELETE /api/v1/files/{id}', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member'); // MEMBER_PERMISSIONS carries files.delete.

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $this->outsideFile = File::factory()->create(); // directory owner cannot see at all
    });

    it('403s a token missing files:delete, despite the permission and the ACL both being fine', function () {
        $file = File::factory()->for($this->dir, 'directory')->create();

        Sanctum::actingAs($this->owner, ['files:read']);

        $this->deleteJson("/api/v1/files/{$file->id}")->assertForbidden();
    });

    it('404s a file whose directory is wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['files:delete']);

        $this->deleteJson("/api/v1/files/{$this->outsideFile->id}")->assertNotFound();
    });

    it('narrows on the next request when the owner\'s role is revoked, no re-issue needed', function () {
        $first = File::factory()->for($this->dir, 'directory')->create();
        $second = File::factory()->for($this->dir, 'directory')->create();

        Sanctum::actingAs($this->owner, ['files:delete']);

        $this->deleteJson("/api/v1/files/{$first->id}")->assertOk();

        actingAsAfterRoleRevoked($this->owner, 'member', ['files:delete']);

        $this->deleteJson("/api/v1/files/{$second->id}")->assertForbidden();
        expect($second->fresh()->trashed())->toBeFalse();
    });
});

// --- properties:write -- PUT /api/v1/directories/{id}/properties -----------
// Shape: ACL-only, gate (c) N/A (DirectoryPolicy::update() is ACL alone).

describe('PUT /api/v1/directories/{id}/properties', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $this->outside = Directory::factory()->create();
    });

    it('403s a token missing properties:write, despite ACL reach', function () {
        Sanctum::actingAs($this->owner, ['properties:read']);

        $this->putJson("/api/v1/directories/{$this->dir->id}/properties", ['values' => []])
            ->assertForbidden();
    });

    it('404s a directory wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['properties:write']);

        $this->putJson("/api/v1/directories/{$this->outside->id}/properties", ['values' => []])
            ->assertNotFound();
    });

    it('has no Spatie permission to narrow -- DirectoryPolicy::update() is ACL-only, same as PropertyPanel::save()', function () {
        $this->owner->syncRoles([]);
        Sanctum::actingAs($this->owner->fresh(), ['properties:write']);

        $this->putJson("/api/v1/directories/{$this->dir->id}/properties", ['values' => []])
            ->assertOk();
    });
});

// --- files:write + directories:write -- POST /api/v1/trash/file/{id}/restore
// Shape: full three gates, dual ability (permission files.restore, ACL edit).

describe('POST /api/v1/trash/file/{id}/restore', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member'); // MEMBER_PERMISSIONS carries files.restore.

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $outsideDir = Directory::factory()->create();
        $this->outsideFile = File::factory()->for($outsideDir, 'directory')->create();
        $this->outsideFile->delete();
    });

    it('403s a token missing directories:write, despite files:write, the permission and the ACL all being fine', function () {
        $file = File::factory()->for($this->dir, 'directory')->create();
        $file->delete();

        Sanctum::actingAs($this->owner, ['files:write']);

        $this->postJson("/api/v1/trash/file/{$file->id}/restore")->assertForbidden();
    });

    it('404s a trashed file wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['files:write', 'directories:write']);

        $this->postJson("/api/v1/trash/file/{$this->outsideFile->id}/restore")->assertNotFound();
    });

    it('narrows on the next request when the owner\'s role is revoked, no re-issue needed', function () {
        $first = File::factory()->for($this->dir, 'directory')->create();
        $first->delete();
        $second = File::factory()->for($this->dir, 'directory')->create();
        $second->delete();

        Sanctum::actingAs($this->owner, ['files:write', 'directories:write']);

        $this->postJson("/api/v1/trash/file/{$first->id}/restore")->assertOk();

        actingAsAfterRoleRevoked($this->owner, 'member', ['files:write', 'directories:write']);

        $this->postJson("/api/v1/trash/file/{$second->id}/restore")->assertForbidden();
        expect(File::onlyTrashed()->find($second->id))->not->toBeNull();
    });
});

// --- search -- GET /api/v1/search -------------------------------------------
// Shape: listing filtered by ACL, no authorize() call; gate (c) via a
// ROLE-GRANTED DirectoryGrant, since Search has no Spatie permission of
// its own to revoke.

describe('GET /api/v1/search', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');

        $this->dir = Directory::factory()->create();
        $memberRoleId = $this->owner->roles()->first()->getKey();

        // Made to the ROLE, not the user directly: this is what lets
        // "revoking the owner's role" narrow the ACL itself below, not
        // merely a Spatie permission.
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'role',
            'grantee_id' => $memberRoleId,
            'level' => AccessLevel::View,
        ]);

        $this->visibleFile = File::factory()->for($this->dir, 'directory')->create(['name' => 'Quarterly Zephyr Report']);

        $hiddenDir = Directory::factory()->create();
        File::factory()->for($hiddenDir, 'directory')->create(['name' => 'Quarterly Zephyr Report']);
    });

    it('403s a token missing search, despite ACL reach', function () {
        Sanctum::actingAs($this->owner, ['files:read']);

        $this->getJson('/api/v1/search?q=Zephyr')->assertForbidden();
    });

    it('never returns a hit from a directory outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['search']);

        $response = $this->getJson('/api/v1/search?q=Zephyr')->assertOk();

        $ids = collect($response->json('data'))->pluck('subject_id');

        expect($ids)->toContain($this->visibleFile->id)
            ->and($ids)->toHaveCount(1);
    });

    it('narrows on the next request when the owner\'s role -- and so its role-granted ACL -- is revoked', function () {
        Sanctum::actingAs($this->owner, ['search']);

        $this->getJson('/api/v1/search?q=Zephyr')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // The grant above was made to the ROLE, not the user directly, so
        // removing the role removes the grant's effect too -- this is
        // "revoking the owner's role narrows an existing token" reaching
        // all the way into directory_access, not just Spatie's own
        // permission table.
        actingAsAfterRoleRevoked($this->owner, 'member', ['search']);

        $this->getJson('/api/v1/search?q=Zephyr')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

// ============================================================================
// PART 2 -- every remaining registered route, in routes/api.php's own order.
// ============================================================================

// --- directories:read -- GET /api/v1/directories ----------------------------
// Shape: listing filtered by ACL, no authorize() call.

describe('GET /api/v1/directories', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');
        $memberRoleId = $this->owner->roles()->first()->getKey();

        $this->visible = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->visible->id,
            'grantee_type' => 'role',
            'grantee_id' => $memberRoleId,
            'level' => AccessLevel::View,
        ]);

        $this->hidden = Directory::factory()->create();
    });

    it('403s a token missing directories:read, despite ACL reach', function () {
        Sanctum::actingAs($this->owner, ['files:read']);

        $this->getJson('/api/v1/directories')->assertForbidden();
    });

    it('never lists a directory outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['directories:read']);

        $ids = collect($this->getJson('/api/v1/directories')->assertOk()->json('data'))->pluck('id');

        expect($ids)->toContain($this->visible->id)
            ->and($ids)->not->toContain($this->hidden->id);
    });

    it('narrows on the next request when the owner\'s role -- and so its role-granted ACL -- is revoked', function () {
        Sanctum::actingAs($this->owner, ['directories:read']);

        $ids = collect($this->getJson('/api/v1/directories')->assertOk()->json('data'))->pluck('id');
        expect($ids)->toContain($this->visible->id);

        actingAsAfterRoleRevoked($this->owner, 'member', ['directories:read']);

        $ids = collect($this->getJson('/api/v1/directories')->assertOk()->json('data'))->pluck('id');
        expect($ids)->not->toContain($this->visible->id);
    });
});

// --- directories:read -- GET /api/v1/directories/{id}/files ----------------
// Shape: ACL-only, gate (c) N/A (DirectoryPolicy::view() is ACL alone).

describe('GET /api/v1/directories/{id}/files', function () {
    beforeEach(function () {
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->viewer->id,
            'level' => AccessLevel::View,
        ]);

        $this->hidden = Directory::factory()->create();
    });

    it('403s a token missing directories:read, despite ACL reach', function () {
        Sanctum::actingAs($this->viewer, ['files:read']);

        $this->getJson("/api/v1/directories/{$this->dir->id}/files")->assertForbidden();
    });

    it('404s a directory wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->viewer, ['directories:read']);

        $this->getJson("/api/v1/directories/{$this->hidden->id}/files")->assertNotFound();
    });

    it('has no Spatie permission to narrow -- DirectoryPolicy::view() is ACL-only', function () {
        $this->viewer->syncRoles([]);
        Sanctum::actingAs($this->viewer->fresh(), ['directories:read']);

        $this->getJson("/api/v1/directories/{$this->dir->id}/files")->assertOk();
    });
});

// --- directories:write -- PATCH /api/v1/directories/{id}, renaming ---------
// Shape: ACL-only, gate (c) N/A (DirectoryPolicy::update() is ACL alone).

describe('PATCH /api/v1/directories/{id} (rename)', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $this->outside = Directory::factory()->create();
    });

    it('403s a token missing directories:write, despite ACL reach', function () {
        Sanctum::actingAs($this->owner, ['directories:read']);

        $this->patchJson("/api/v1/directories/{$this->dir->id}", ['name' => 'Renamed'])->assertForbidden();
    });

    it('404s a directory wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['directories:write']);

        $this->patchJson("/api/v1/directories/{$this->outside->id}", ['name' => 'Renamed'])->assertNotFound();
    });

    it('has no Spatie permission to narrow for a rename -- DirectoryPolicy::update() is ACL-only', function () {
        $this->owner->syncRoles([]);
        Sanctum::actingAs($this->owner->fresh(), ['directories:write']);

        $this->patchJson("/api/v1/directories/{$this->dir->id}", ['name' => 'Renamed'])->assertOk();
    });
});

// --- directories:write -- PATCH /api/v1/directories/{id}, moving -----------
// Shape: full three gates (permission directories.manage, ACL manage+edit).

describe('PATCH /api/v1/directories/{id} (move)', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole(roleWithPermissions('directory-mover', ['directories.manage']));

        $this->source = Directory::factory()->create();
        $this->destination = Directory::factory()->create();

        DirectoryGrant::create([
            'directory_id' => $this->source->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Manage,
        ]);
        DirectoryGrant::create([
            'directory_id' => $this->destination->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $this->outside = Directory::factory()->create();
    });

    it('403s a token missing directories:write, despite the permission and the ACL both being fine', function () {
        $child = Directory::factory()->for($this->source, 'parent')->create();

        Sanctum::actingAs($this->owner, ['directories:read']);

        $this->patchJson("/api/v1/directories/{$child->id}", ['parent_id' => $this->destination->id])
            ->assertForbidden();
    });

    it('404s a destination wholly outside the token owner\'s reach', function () {
        $child = Directory::factory()->for($this->source, 'parent')->create();

        Sanctum::actingAs($this->owner, ['directories:write']);

        $this->patchJson("/api/v1/directories/{$child->id}", ['parent_id' => $this->outside->id])
            ->assertNotFound();
    });

    it('narrows on the next request when directories.manage is revoked, no re-issue needed', function () {
        $first = Directory::factory()->for($this->source, 'parent')->create();
        $second = Directory::factory()->for($this->source, 'parent')->create();

        Sanctum::actingAs($this->owner, ['directories:write']);

        $this->patchJson("/api/v1/directories/{$first->id}", ['parent_id' => $this->destination->id])
            ->assertOk();

        actingAsAfterRoleRevoked($this->owner, 'directory-mover', ['directories:write']);

        $this->patchJson("/api/v1/directories/{$second->id}", ['parent_id' => $this->destination->id])
            ->assertForbidden();

        expect($second->fresh()->parent_id)->toBe($this->source->id);
    });
});

// --- directories:write -- DELETE /api/v1/directories/{id} ------------------
// Shape: full three gates (permission directories.manage, ACL manage).

describe('DELETE /api/v1/directories/{id}', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole(roleWithPermissions('directory-deleter', ['directories.manage']));

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Manage,
        ]);

        $this->outside = Directory::factory()->create();
    });

    it('403s a token missing directories:write, despite the permission and the ACL both being fine', function () {
        $child = Directory::factory()->for($this->dir, 'parent')->create();

        Sanctum::actingAs($this->owner, ['directories:read']);

        $this->deleteJson("/api/v1/directories/{$child->id}")->assertForbidden();
    });

    it('404s a directory wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['directories:write']);

        $this->deleteJson("/api/v1/directories/{$this->outside->id}")->assertNotFound();
    });

    it('narrows on the next request when the owner\'s role is revoked, no re-issue needed', function () {
        $first = Directory::factory()->for($this->dir, 'parent')->create();
        $second = Directory::factory()->for($this->dir, 'parent')->create();

        Sanctum::actingAs($this->owner, ['directories:write']);

        $this->deleteJson("/api/v1/directories/{$first->id}")->assertOk();

        actingAsAfterRoleRevoked($this->owner, 'directory-deleter', ['directories:write']);

        $this->deleteJson("/api/v1/directories/{$second->id}")->assertForbidden();
        expect($second->fresh()->trashed())->toBeFalse();
    });
});

// --- files:write -- PATCH /api/v1/files/{id}, renaming ---------------------
// Shape: ACL-only, gate (c) N/A (FilePolicy::update() is ACL alone).

describe('PATCH /api/v1/files/{id} (rename)', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $this->outsideFile = File::factory()->create();
    });

    it('403s a token missing files:write, despite ACL reach', function () {
        $file = File::factory()->for($this->dir, 'directory')->create();

        Sanctum::actingAs($this->owner, ['files:read']);

        $this->patchJson("/api/v1/files/{$file->id}", ['name' => 'renamed.pdf'])->assertForbidden();
    });

    it('404s a file whose directory is wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['files:write']);

        $this->patchJson("/api/v1/files/{$this->outsideFile->id}", ['name' => 'renamed.pdf'])
            ->assertNotFound();
    });

    it('has no Spatie permission to narrow for a rename -- FilePolicy::update() is ACL-only', function () {
        $file = File::factory()->for($this->dir, 'directory')->create();

        $this->owner->syncRoles([]);
        Sanctum::actingAs($this->owner->fresh(), ['files:write']);

        $this->patchJson("/api/v1/files/{$file->id}", ['name' => 'renamed.pdf'])->assertOk();
    });
});

// --- files:write -- PATCH /api/v1/files/{id}, moving ------------------------
// Shape: ACL-only on BOTH ends, gate (c) N/A -- FilePolicy::move() has no
// Spatie permission clause at all, unlike DirectoryPolicy::move().

describe('PATCH /api/v1/files/{id} (move)', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');

        $this->from = Directory::factory()->create();
        $this->to = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->from->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);
        DirectoryGrant::create([
            'directory_id' => $this->to->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);

        $this->outside = Directory::factory()->create();
    });

    it('403s a token missing files:write, despite ACL reach on both ends', function () {
        $file = File::factory()->for($this->from, 'directory')->create();

        Sanctum::actingAs($this->owner, ['files:read']);

        $this->patchJson("/api/v1/files/{$file->id}", ['directory_id' => $this->to->id])->assertForbidden();
    });

    it('404s a destination wholly outside the token owner\'s reach', function () {
        $file = File::factory()->for($this->from, 'directory')->create();

        Sanctum::actingAs($this->owner, ['files:write']);

        $this->patchJson("/api/v1/files/{$file->id}", ['directory_id' => $this->outside->id])
            ->assertNotFound();
    });

    it('has no Spatie permission to narrow for a move -- FilePolicy::move() checks ACL on both ends only', function () {
        $file = File::factory()->for($this->from, 'directory')->create();

        $this->owner->syncRoles([]);
        Sanctum::actingAs($this->owner->fresh(), ['files:write']);

        $this->patchJson("/api/v1/files/{$file->id}", ['directory_id' => $this->to->id])->assertOk();
    });
});

// --- files:read -- GET /api/v1/files/{id} -----------------------------------
// Shape: ACL-only, gate (c) N/A (FilePolicy::view() is ACL alone).

describe('GET /api/v1/files/{id}', function () {
    beforeEach(function () {
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->viewer->id,
            'level' => AccessLevel::View,
        ]);
        $this->file = File::factory()->for($this->dir, 'directory')->create();

        $this->outsideFile = File::factory()->create();
    });

    it('403s a token missing files:read, despite ACL reach', function () {
        Sanctum::actingAs($this->viewer, ['directories:read']);

        $this->getJson("/api/v1/files/{$this->file->id}")->assertForbidden();
    });

    it('404s a file whose directory is wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->viewer, ['files:read']);

        $this->getJson("/api/v1/files/{$this->outsideFile->id}")->assertNotFound();
    });

    it('has no Spatie permission to narrow -- FilePolicy::view() is ACL-only', function () {
        $this->viewer->syncRoles([]);
        Sanctum::actingAs($this->viewer->fresh(), ['files:read']);

        $this->getJson("/api/v1/files/{$this->file->id}")->assertOk();
    });
});

// --- files:read -- GET /api/v1/files/{id}/download-url ---------------------
// Shape: ACL-only, gate (c) N/A (FilePolicy::download() delegates to view()).

describe('GET /api/v1/files/{id}/download-url', function () {
    beforeEach(function () {
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->viewer->id,
            'level' => AccessLevel::View,
        ]);
        $this->file = File::factory()->for($this->dir, 'directory')->create();
        $version = FileVersion::factory()->for($this->file)->create(['version_number' => 1]);
        $this->file->update(['current_version_id' => $version->id]);

        Storage::disk('documents')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expires): string => "https://minio.test/{$path}",
        );

        $this->outsideFile = File::factory()->create();
    });

    it('403s a token missing files:read, despite ACL reach', function () {
        Sanctum::actingAs($this->viewer, ['directories:read']);

        $this->getJson("/api/v1/files/{$this->file->id}/download-url")->assertForbidden();
    });

    it('404s a file whose directory is wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->viewer, ['files:read']);

        $this->getJson("/api/v1/files/{$this->outsideFile->id}/download-url")->assertNotFound();
    });

    it('has no Spatie permission to narrow -- FilePolicy::download() is view(), ACL-only', function () {
        $this->viewer->syncRoles([]);
        Sanctum::actingAs($this->viewer->fresh(), ['files:read']);

        $this->getJson("/api/v1/files/{$this->file->id}/download-url")->assertOk();
    });
});

// --- files:read -- GET /api/v1/files/{id}/versions --------------------------
// Shape: ACL-only, gate (c) N/A (FilePolicy::view() is ACL alone).

describe('GET /api/v1/files/{id}/versions', function () {
    beforeEach(function () {
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->viewer->id,
            'level' => AccessLevel::View,
        ]);
        $this->file = File::factory()->for($this->dir, 'directory')->create();

        $this->outsideFile = File::factory()->create();
    });

    it('403s a token missing files:read, despite ACL reach', function () {
        Sanctum::actingAs($this->viewer, ['directories:read']);

        $this->getJson("/api/v1/files/{$this->file->id}/versions")->assertForbidden();
    });

    it('404s a file whose directory is wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->viewer, ['files:read']);

        $this->getJson("/api/v1/files/{$this->outsideFile->id}/versions")->assertNotFound();
    });

    it('has no Spatie permission to narrow -- FilePolicy::view() is ACL-only', function () {
        $this->viewer->syncRoles([]);
        Sanctum::actingAs($this->viewer->fresh(), ['files:read']);

        $this->getJson("/api/v1/files/{$this->file->id}/versions")->assertOk();
    });
});

// --- files:read -- GET /api/v1/files/{id}/text ------------------------------
// Shape: ACL-only, gate (c) N/A (FilePolicy::view() is ACL alone).

describe('GET /api/v1/files/{id}/text', function () {
    beforeEach(function () {
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->viewer->id,
            'level' => AccessLevel::View,
        ]);
        $this->file = File::factory()->for($this->dir, 'directory')->create();
        $version = FileVersion::factory()->for($this->file)->create(['version_number' => 1]);
        $this->file->update(['current_version_id' => $version->id]);
        FileText::factory()->create(['file_version_id' => $version->id]);

        $this->outsideFile = File::factory()->create();
    });

    it('403s a token missing files:read, despite ACL reach', function () {
        Sanctum::actingAs($this->viewer, ['directories:read']);

        $this->getJson("/api/v1/files/{$this->file->id}/text")->assertForbidden();
    });

    it('404s a file whose directory is wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->viewer, ['files:read']);

        $this->getJson("/api/v1/files/{$this->outsideFile->id}/text")->assertNotFound();
    });

    it('has no Spatie permission to narrow -- FilePolicy::view() is ACL-only', function () {
        $this->viewer->syncRoles([]);
        Sanctum::actingAs($this->viewer->fresh(), ['files:read']);

        $this->getJson("/api/v1/files/{$this->file->id}/text")->assertOk();
    });
});

// --- properties:read -- GET /api/v1/property-definitions -------------------
// Shape: neither gate applies. No ACL dimension (instance-wide metadata,
// same as PropertyDefinitionController's own docblock) and no Policy call
// at all, so there is no ACL to deny and no Spatie permission to revoke.

describe('GET /api/v1/property-definitions', function () {
    it('403s a token missing properties:read', function () {
        $user = User::factory()->create();
        $user->assignRole('member');

        Sanctum::actingAs($user, ['files:read']);

        $this->getJson('/api/v1/property-definitions')->assertForbidden();
    });

    it('gates (b) and (c) do not apply: a user with no roles and no directory grants at all still sees every definition', function () {
        PropertyDefinition::factory()->create(['key' => 'due_date']);

        $user = User::factory()->create(); // no role, no grant of any kind
        Sanctum::actingAs($user, ['properties:read']);

        $keys = collect($this->getJson('/api/v1/property-definitions')->assertOk()->json('data'))->pluck('key');

        expect($keys)->toContain('due_date');
    });
});

// --- properties:write -- PUT /api/v1/files/{id}/properties -----------------
// Shape: ACL-only, gate (c) N/A (FilePolicy::update() is ACL alone).

describe('PUT /api/v1/files/{id}/properties', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'user',
            'grantee_id' => $this->owner->id,
            'level' => AccessLevel::Edit,
        ]);
        $this->file = File::factory()->for($this->dir, 'directory')->create();

        $this->outsideFile = File::factory()->create();
    });

    it('403s a token missing properties:write, despite ACL reach', function () {
        Sanctum::actingAs($this->owner, ['properties:read']);

        $this->putJson("/api/v1/files/{$this->file->id}/properties", ['values' => []])->assertForbidden();
    });

    it('404s a file whose directory is wholly outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['properties:write']);

        $this->putJson("/api/v1/files/{$this->outsideFile->id}/properties", ['values' => []])
            ->assertNotFound();
    });

    it('has no Spatie permission to narrow -- FilePolicy::update() is ACL-only, same as PropertyPanel::save()', function () {
        $this->owner->syncRoles([]);
        Sanctum::actingAs($this->owner->fresh(), ['properties:write']);

        $this->putJson("/api/v1/files/{$this->file->id}/properties", ['values' => []])->assertOk();
    });
});

// --- files:read + directories:read -- GET /api/v1/trash --------------------
// Shape: listing filtered by ACL, no authorize() call; gate (c) via a
// ROLE-GRANTED DirectoryGrant, same reasoning as GET /api/v1/search and
// GET /api/v1/directories above.

describe('GET /api/v1/trash', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->owner->assignRole('member');
        $memberRoleId = $this->owner->roles()->first()->getKey();

        $this->dir = Directory::factory()->create();
        DirectoryGrant::create([
            'directory_id' => $this->dir->id,
            'grantee_type' => 'role',
            'grantee_id' => $memberRoleId,
            'level' => AccessLevel::View,
        ]);
        $this->file = File::factory()->for($this->dir, 'directory')->create();
        $this->file->delete();

        $outsideDir = Directory::factory()->create();
        $this->outsideFile = File::factory()->for($outsideDir, 'directory')->create();
        $this->outsideFile->delete();
    });

    it('403s a token missing directories:read, despite files:read and ACL reach', function () {
        Sanctum::actingAs($this->owner, ['files:read']);

        $this->getJson('/api/v1/trash')->assertForbidden();
    });

    it('never lists a trashed file outside the token owner\'s reach', function () {
        Sanctum::actingAs($this->owner, ['files:read', 'directories:read']);

        $ids = collect($this->getJson('/api/v1/trash')->assertOk()->json('data.files'))->pluck('id');

        expect($ids)->toContain($this->file->id)
            ->and($ids)->not->toContain($this->outsideFile->id);
    });

    it('narrows on the next request when the owner\'s role -- and so its role-granted ACL -- is revoked', function () {
        Sanctum::actingAs($this->owner, ['files:read', 'directories:read']);

        $ids = collect($this->getJson('/api/v1/trash')->assertOk()->json('data.files'))->pluck('id');
        expect($ids)->toContain($this->file->id);

        actingAsAfterRoleRevoked($this->owner, 'member', ['files:read', 'directories:read']);

        $ids = collect($this->getJson('/api/v1/trash')->assertOk()->json('data.files'))->pluck('id');
        expect($ids)->not->toContain($this->file->id);
    });
});
