<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

// The topbar shell (spec §10, "Application shell") is rendered by every
// #[Layout('layouts::app')] page. files.browse is the simplest such page and
// is also the default landing after login, so it doubles as the assertion
// surface for both the nav contract and the login redirect.
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the wordmark, version pill, and primary nav for a plain member, without settings', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $response = $this->actingAs($member)->get(route('files.browse'));

    $response->assertOk()
        ->assertSee('doccum')
        ->assertSee('v'.config('doccum.version'))
        ->assertSee(__('Home'))
        ->assertSee(__('Files'))
        ->assertSee(__('My Account'))
        // A plain member has none of the admin permissions, so the
        // Settings destination must not appear in the primary nav at all --
        // not merely be disabled or unlinked.
        ->assertDontSee(__('Settings'));
});

it('shows settings in the primary nav to a user with the properties.manage permission', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get(route('files.browse'));

    $response->assertOk()->assertSee(__('Settings'));
});

// item/admin-users (issue #18): spec §10 gates each Settings SECTION by its
// own permission, so a viewer holding ONLY users.manage (no admin role,
// which would hold properties.manage too and mask this) must still see a
// way into Settings -- pointed at the one section they can actually reach.
// A direct permission grant, not a role, is deliberate: RolesAndPermissionsSeeder's
// only two roles are 'member' (neither permission) and 'admin' (both), so
// nothing already isolates "users.manage but not properties.manage" without
// one.
it('shows settings in the primary nav, pointed at the users page, for a viewer holding only users.manage', function () {
    $usersAdmin = User::factory()->create();
    $usersAdmin->givePermissionTo('users.manage');

    $response = $this->actingAs($usersAdmin)->get(route('files.browse'));

    $response->assertOk()
        ->assertSee(__('Settings'))
        ->assertSeeHtml('data-test="nav-settings-users"')
        ->assertSeeHtml(route('admin.users'))
        // ... and NOT the properties section, which they cannot reach.
        ->assertDontSeeHtml('data-test="nav-settings"');
});

// item/admin-roles (issue #19). The case nothing covered, and the one that was
// actually broken in the product: the seeded `admin` role holds EVERY
// permission, properties.manage and users.manage included. While the two
// entries were mutually exclusive (@can/@elsecan) the first branch always won,
// so the only administrator a shipped instance has saw the properties section
// and had NO link to Users or Roles anywhere. Every existing test passed --
// they reach those pages by route() -- and so did the container smoke, which
// navigates by URL. The test above isolates users.manage with a DIRECT grant
// precisely because the admin role masks this, which is why the gap survived.
it('shows every settings section an administrator holding all permissions can reach', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect($admin->can('properties.manage'))->toBeTrue()
        ->and($admin->can('users.manage'))->toBeTrue();

    $response = $this->actingAs($admin)->get(route('files.browse'));

    $response->assertOk()
        ->assertSeeHtml('data-test="nav-settings"')
        ->assertSeeHtml('data-test="nav-settings-users"')
        ->assertSeeHtml('data-test="nav-settings-roles"')
        ->assertSeeHtml(route('admin.properties'))
        ->assertSeeHtml(route('admin.users'))
        ->assertSeeHtml(route('admin.roles'));
});

// Hiding the nav item is an affordance, not access control: strip the route's
// permission middleware and the assertion above still passes, because the link
// simply is not rendered. The guarantee only holds if the destination refuses.
//
// Deliberately not in .github/mutations.json. The destination is guarded twice
// and independently -- `can:properties.manage` on the route, and
// PropertyDefinitions::mount()'s authorize('viewAny') -- so removing either one
// leaves the other returning 403 and these tests still pass. A mutation entry
// takes a single `remove` string, so no single entry can prove either guard.
// Recording one anyway would assert exactly the false confidence CLAUDE.md's
// mutation rule exists to prevent.
it('refuses a plain member the settings destination at the route, not merely in the nav', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)->get(route('admin.properties'))->assertForbidden();
});

it('allows an admin the settings destination', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get(route('admin.properties'))->assertOk();
});
