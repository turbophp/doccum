<?php

declare(strict_types=1);

use App\Livewire\Setup\FirstRun;
use App\Models\Directory;
use App\Models\User;
use App\Services\Settings;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

// Deliberately does NOT seed roles. Every other test in this suite calls
// $this->seed(RolesAndPermissionsSeeder::class) first, which is exactly why a
// genuinely fresh install crashed at the final step of setup with
// "There is no role named `admin` for guard `web`" and no test noticed.

it('has no roles before anything runs', function () {
    expect(Role::count())->toBe(0);
});

it('completes setup on a database that has never been seeded', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 3)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'Correct-Horse-Battery9')
        ->set('password_confirmation', 'Correct-Horse-Battery9')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('files.browse'));

    $user = User::firstOrFail();

    expect($user->hasRole('admin'))->toBeTrue()
        ->and(Role::whereIn('name', ['admin', 'member'])->count())->toBe(2)
        ->and(app(Settings::class)->get('instance.name'))->toBe('Acme Docs')
        ->and(Directory::where('home_user_id', $user->id)->value('name'))->toBe('ada');
});

it('creates roles idempotently', function () {
    $this->artisan('doccum:ensure-roles')->assertSuccessful();
    $this->artisan('doccum:ensure-roles')->assertSuccessful();

    expect(Role::where('name', 'admin')->count())->toBe(1);
});
