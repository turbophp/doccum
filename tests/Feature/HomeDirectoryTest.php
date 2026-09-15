<?php

declare(strict_types=1);

use App\Actions\Users\CreateHomeDirectory;
use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\User;
use App\Services\DirectoryAccess;
use App\Services\Settings;

it('creates a home directory named for the username', function () {
    $user = User::factory()->create(['username' => 'ada']);

    $home = app(CreateHomeDirectory::class)->handle($user);

    expect($home->name)->toBe('ada')
        ->and($home->home_user_id)->toBe($user->id)
        ->and($home->parent_id)->toBeNull()
        ->and($home->path)->toBe("/{$home->id}/");
});

it('gives the owner manage on their home', function () {
    $user = User::factory()->create(['username' => 'ada']);
    $home = app(CreateHomeDirectory::class)->handle($user);

    expect(app(DirectoryAccess::class)->levelFor($user, $home))->toBe(AccessLevel::Manage);
});

it('gives the owner access to nothing else', function () {
    $other = Directory::factory()->create();
    $user = User::factory()->create(['username' => 'ada']);
    app(CreateHomeDirectory::class)->handle($user);

    expect(app(DirectoryAccess::class)->levelFor($user, $other))->toBeNull();
});

it('is idempotent', function () {
    $user = User::factory()->create(['username' => 'ada']);

    $first = app(CreateHomeDirectory::class)->handle($user);
    $second = app(CreateHomeDirectory::class)->handle($user);

    expect($second->id)->toBe($first->id)
        ->and(Directory::where('home_user_id', $user->id)->count())->toBe(1);
});

it('does nothing when auto home is disabled', function () {
    app(Settings::class)->set('directories.auto_home', false);
    $user = User::factory()->create(['username' => 'ada']);

    expect(app(CreateHomeDirectory::class)->handle($user))->toBeNull()
        ->and(Directory::count())->toBe(0);
});

it('creates a home when a user registers', function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'ada',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect(Directory::where('home_user_id', $user->id)->value('name'))->toBe('ada')
        ->and($user->hasRole('member'))->toBeTrue();
});
