<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use App\Services\DirectoryAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * item/directory-access-ui (issue #14): granting and revoking directory
 * access through the Files browser's detail panel.
 *
 * DirectoryPolicy::manageAccess() gates BOTH grantAccess() and
 * revokeAccess() below, and it requires ONLY Manage-level directory_access
 * -- no additional Spatie permission. That is deliberately different from
 * move()/delete(), which also require directories.manage, and it is not
 * this item's invention: manageAccess() and DirectoryPolicyTest's 'requires
 * manage to grant access' both predate this item (see git history) and
 * that existing test's second assertion is exactly "a plain member role
 * with nothing but a Manage grant passes manageAccess()". Requiring a
 * Spatie permission here as well, the way move()/delete() do, would break
 * that already-passing test -- flagged in the task report rather than
 * resolved by weakening it.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = User::factory()->create();
    $this->manager->assignRole('member');

    $this->dir = Directory::factory()->create(['name' => 'Managed']);

    DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->manager->id,
        'level' => AccessLevel::Manage,
    ]);

    $this->grantee = User::factory()->create(['email' => 'grantee@example.com']);
    $this->grantee->assignRole('member');
});

// --- the control itself is absent, not merely disabled, for a non-manager --

it('shows no access control to a viewer holding only view access', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');

    DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $viewer->id,
        'level' => AccessLevel::View,
    ]);

    Livewire::actingAs($viewer)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->assertDontSeeHtml('data-test="directory-access-panel"')
        ->assertDontSeeHtml('data-test="grant-access-form"');
});

it('shows no access control to an editor without manage access', function () {
    $editor = User::factory()->create();
    $editor->assignRole('member');

    DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $editor->id,
        'level' => AccessLevel::Edit,
    ]);

    Livewire::actingAs($editor)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->assertDontSeeHtml('data-test="directory-access-panel"');
});

it('shows the access control to a manager', function () {
    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->assertSeeHtml('data-test="directory-access-panel"')
        ->assertSeeHtml('data-test="grant-access-form"');
});

// --- server-side refusal, independent of what the control renders ---------

it('refuses to grant access server-side for a caller without manage access', function () {
    $editor = User::factory()->create();
    $editor->assignRole('member');

    DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $editor->id,
        'level' => AccessLevel::Edit,
    ]);

    Livewire::actingAs($editor)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->set('grantEmail', $this->grantee->email)
        ->set('grantLevel', 'view')
        ->call('grantAccess')
        ->assertForbidden();

    expect(
        DirectoryGrant::query()
            ->where('directory_id', $this->dir->id)
            ->where('grantee_id', $this->grantee->id)
            ->exists()
    )->toBeFalse();
});

it('refuses to revoke access server-side for a caller without manage access', function () {
    $editor = User::factory()->create();
    $editor->assignRole('member');

    DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $editor->id,
        'level' => AccessLevel::Edit,
    ]);

    $grant = DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->grantee->id,
        'level' => AccessLevel::View,
    ]);

    Livewire::actingAs($editor)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->call('revokeAccess', $grant->id)
        ->assertForbidden();

    expect(DirectoryGrant::query()->whereKey($grant->id)->exists())->toBeTrue();
});

// --- grant --------------------------------------------------------------

it('grants access through the panel, writing exactly one DirectoryGrant row via the model', function () {
    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->set('grantEmail', $this->grantee->email)
        ->set('grantLevel', 'edit')
        ->call('grantAccess')
        ->assertHasNoErrors();

    $grants = DirectoryGrant::query()
        ->where('directory_id', $this->dir->id)
        ->where('grantee_type', 'user')
        ->where('grantee_id', $this->grantee->id)
        ->get();

    expect($grants)->toHaveCount(1)
        ->and($grants->first()->level)->toBe(AccessLevel::Edit);
});

it('raises an existing grant to a new level instead of creating a second row', function () {
    DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->grantee->id,
        'level' => AccessLevel::View,
    ]);

    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->set('grantEmail', $this->grantee->email)
        ->set('grantLevel', 'manage')
        ->call('grantAccess')
        ->assertHasNoErrors();

    $grants = DirectoryGrant::query()
        ->where('directory_id', $this->dir->id)
        ->where('grantee_id', $this->grantee->id)
        ->get();

    expect($grants)->toHaveCount(1)
        ->and($grants->first()->level)->toBe(AccessLevel::Manage);
});

it('rejects granting access to an email with no matching user', function () {
    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->set('grantEmail', 'nobody-here@example.com')
        ->set('grantLevel', 'view')
        ->call('grantAccess')
        ->assertHasErrors('grantEmail');

    expect(
        DirectoryGrant::query()->where('directory_id', $this->dir->id)->count()
    )->toBe(1); // only the manager's own seeded grant from beforeEach()
});

/**
 * THE doneWhen clause this item is named for: "a grant is visible to the
 * grantee in the same request cycle."
 *
 * $access is resolved from the container BEFORE granting, which -- because
 * App\Services\DirectoryAccess is a per-request singleton (see
 * DoccumServiceProvider) -- warms its memoisation to "no access" for
 * ($this->grantee, $this->dir): DirectoryAccess::resolve()'s null branch,
 * cached via array_key_exists() rather than left absent (see that method's
 * own docblock on why a denial is cached too).
 *
 * If DoccumServiceProvider's `DirectoryGrant::saved($flushAccess)` wiring
 * were removed, the SECOND assertion below would still read the cached
 * null and this test would fail, even though GrantDirectoryAccess wrote a
 * real row: nothing would have told the singleton its answer had gone
 * stale. That is the one line this test is written to catch.
 */
it('is visible to the grantee in the same request cycle the grant is made', function () {
    $access = app(DirectoryAccess::class);
    expect($access->can($this->grantee, $this->dir, AccessLevel::View))->toBeFalse();

    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->set('grantEmail', $this->grantee->email)
        ->set('grantLevel', 'view')
        ->call('grantAccess')
        ->assertHasNoErrors();

    expect($access->can($this->grantee, $this->dir, AccessLevel::View))->toBeTrue();
});

// --- revoke ---------------------------------------------------------------

it('revokes access through the panel', function () {
    $grant = DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->grantee->id,
        'level' => AccessLevel::Edit,
    ]);

    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->call('revokeAccess', $grant->id)
        ->assertHasNoErrors();

    expect(DirectoryGrant::query()->whereKey($grant->id)->exists())->toBeFalse();
});

/**
 * The revoke half of the same doneWhen clause, same reasoning as the grant
 * test above, mirrored: warm the memoisation to "has access" first, revoke,
 * then check again in the same test -- i.e. the same request cycle.
 */
it('is no longer visible to the grantee in the same request cycle the grant is revoked', function () {
    $grant = DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->grantee->id,
        'level' => AccessLevel::Edit,
    ]);

    $access = app(DirectoryAccess::class);
    expect($access->can($this->grantee, $this->dir, AccessLevel::Edit))->toBeTrue();

    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->call('revokeAccess', $grant->id)
        ->assertHasNoErrors();

    expect($access->can($this->grantee, $this->dir, AccessLevel::Edit))->toBeFalse();
});

it('refuses to revoke a grant belonging to a different directory', function () {
    $otherDir = Directory::factory()->create();

    $otherGrant = DirectoryGrant::create([
        'directory_id' => $otherDir->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->grantee->id,
        'level' => AccessLevel::View,
    ]);

    Livewire::actingAs($this->manager)
        ->test(Browser::class)
        ->call('selectDirectory', $this->dir->id)
        ->call('revokeAccess', $otherGrant->id)
        ->assertNotFound();

    expect(DirectoryGrant::query()->whereKey($otherGrant->id)->exists())->toBeTrue();
});
