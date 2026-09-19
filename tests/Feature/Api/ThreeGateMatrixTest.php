<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\ApiTokenAbility;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * item/api-content (issue #23), doneWhen clause 1: "Per-endpoint three-gate
 * matrix: a missing ability is 403 despite the permission, a denied ACL is
 * 404 or 403 [settled: 404], and revoking the owner's role narrows an
 * existing token on the next request; ... no re-issue needed."
 *
 * A representative endpoint per fixed ability (App\Enums\ApiTokenAbility)
 * gets its own three tests here, chosen so that between them every one of
 * the eight abilities is exercised. Two of them -- directories:read and
 * properties:write -- gate an endpoint whose Policy has NO Spatie
 * permission of its own beyond directory_access (DirectoryPolicy::view()
 * and update() are both ACL-only); for those, gate (c) has nothing to
 * revoke and is asserted as such rather than faked (CLAUDE.md,
 * decision/0080). Every OTHER registered route shares its gate (a)
 * behaviour with one of these, proven generically -- not per-route -- by
 * "403s every /api/v1 route..." below, which reads the abilities straight
 * off Route::getRoutes() rather than a hand-typed list. See this item's
 * report for exactly which routes got a full three-gate test here and
 * which did not.
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

// --- directories:write -- POST /api/v1/directories -------------------------

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
