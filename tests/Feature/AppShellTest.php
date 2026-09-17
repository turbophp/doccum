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
