<?php

declare(strict_types=1);

use App\Livewire\Setup\FirstRun;
use App\Models\Directory;
use App\Models\User;
use App\Services\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('redirects to setup while the instance has no users', function () {
    $this->get('/')->assertRedirect(route('setup'));
    $this->get(route('login'))->assertRedirect(route('setup'));
});

it('serves the setup screen when there are no users', function () {
    $this->get(route('setup'))->assertOk();
});

it('creates the first admin with a home directory', function () {
    Livewire::test(FirstRun::class)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $user = User::firstOrFail();

    expect($user->username)->toBe('ada')
        ->and($user->hasRole('admin'))->toBeTrue()
        ->and(app(Settings::class)->get('instance.name'))->toBe('Acme Docs')
        ->and(Directory::where('home_user_id', $user->id)->value('name'))->toBe('ada')
        ->and(auth()->check())->toBeTrue();
});

it('folds the first admin\'s email to lowercase even when submitted with capitals', function () {
    Livewire::test(FirstRun::class)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'Ada@Example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit')
        ->assertHasNoErrors();

    // Compared in PHP, not through a where() on the folded column: MySQL's
    // utf8mb4_unicode_ci makes where('email', 'Ada@Example.com') match the
    // folded row, so a query-based assertion here would be the very
    // driver-dependent comparison this item removes. See decision/0010.
    expect(User::query()->where('username', 'ada')->value('email'))
        ->toBe('ada@example.com');
});

// Issue #59's finding #1, and the real headline: this installer is the one
// path that creates doccum's very first user, and it bypasses Fortify's own
// RegisteredUserController (and the lowercase_usernames fold that controller
// applies) entirely -- so before this item, whatever case the operator typed
// into the admin email field is exactly what got stored, verbatim.
//
// Fortify's login pipeline, separately, already folds the submitted login
// credential to lowercase (config('fortify.lowercase_usernames'), wired in
// via Laravel\Fortify\Actions\CanonicalizeUsername) -- so before the fix, an
// admin who registered as "Ada@Example.com" and later logs in as
// "ada@example.com" (or types it in any other case) hits a guard->attempt()
// comparing the folded input against the UNfolded stored row: a byte
// comparison on SQLite and PostgreSQL that never matches. That admin cannot
// log in AT ALL on those two drivers -- worse than the uniqueness divergence
// issue #59 itself describes, and the reason this item adds its own explicit
// App\Actions\Fortify\AuthenticateUser rather than leaning on that config
// flag (decision/0010).
it('lets the first admin log back in with different case than they registered with', function () {
    Livewire::test(FirstRun::class)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'Ada@Example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
    $this->post(route('logout'));
    $this->assertGuest();

    $response = $this->post(route('login.store'), [
        'email' => 'ada@example.com',
        'password' => 'password-please',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

it('validates the first admin', function () {
    Livewire::test(FirstRun::class)
        ->set('username', 'Not A Username!')
        ->set('email', 'nope')
        ->call('submit')
        ->assertHasErrors(['username', 'email', 'name', 'password']);

    expect(User::count())->toBe(0);
});

it('closes setup once a user exists', function () {
    User::factory()->create();

    $this->get(route('setup'))->assertNotFound();
});

it('stops redirecting once a user exists', function () {
    User::factory()->create();

    $this->get(route('login'))->assertOk();
});

it('lets livewire requests through while the instance is unconfigured', function () {
    // Regression: the setup form is a Livewire component, so its submission is
    // a POST to Livewire's update endpoint. Redirecting that to /setup made the
    // form impossible to submit -- the page rendered fine and every submission
    // silently bounced. Livewire::test() bypasses HTTP middleware, so only a
    // test at this layer catches it.
    expect(User::query()->exists())->toBeFalse();

    $livewireRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => str_ends_with((string) $route->getName(), 'livewire.update'));

    expect($livewireRoute)->not->toBeNull();

    $response = $this->post('/'.ltrim($livewireRoute->uri(), '/'), []);

    expect($response->isRedirect(route('setup')))->toBeFalse();
});
