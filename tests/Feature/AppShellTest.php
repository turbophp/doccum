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
