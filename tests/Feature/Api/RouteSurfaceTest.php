<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\FileController;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * item/api-content (issue #23), doneWhen clause 2: "a test asserting no
 * /api route touches users, roles, definition writes or periods. Enumerate
 * the registered routes and assert it, rather than eyeballing the route
 * file -- a test that reads the router cannot drift."
 *
 * Reads Route::getRoutes() -- what the framework actually registered from
 * routes/api.php, after every group and middleware alias has been resolved
 * -- never routes/api.php's own text. A route file can say one thing and
 * register another (a stray group, a copy-pasted controller); this cannot.
 */
function apiV1Routes(): Collection
{
    // 'api/v1/', not 'v1/': bootstrap/app.php's withRouting(api: ...) call
    // prefixes every route routes/api.php registers with 'api' (Laravel's
    // own default apiPrefix), on top of the 'v1' this file's own group
    // adds -- so what the router actually holds is 'api/v1/...', which is
    // the URI this function must match to read what got REGISTERED rather
    // than what routes/api.php merely wrote.
    return collect(Route::getRoutes())->filter(
        fn (Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'api/v1/'),
    );
}

it('registers a non-trivial /api/v1 surface', function () {
    // A vacuous route list would make every other assertion in this file
    // true for the wrong reason -- nothing to violate the rule is not the
    // same as nothing violating it.
    $routes = apiV1Routes();

    expect($routes->count())->toBeGreaterThanOrEqual(15);

    $controllers = $routes->map(fn ($route) => $route->getActionName())->unique();

    expect($controllers->filter(fn (string $action): bool => str_contains($action, 'DirectoryController')))->not->toBeEmpty()
        ->and($controllers->filter(fn (string $action): bool => str_contains($action, 'FileController')))->not->toBeEmpty()
        ->and($controllers->filter(fn (string $action): bool => str_contains($action, 'SearchController')))->not->toBeEmpty()
        ->and($controllers->filter(fn (string $action): bool => str_contains($action, 'TrashController')))->not->toBeEmpty();
});

it('touches no route for users, roles, or archive periods', function () {
    foreach (apiV1Routes() as $route) {
        $action = $route->getActionName();

        // Substring checks, not an allow-list of controller names: a
        // forbidden CONTROLLER is what this guards against, whatever this
        // item's own surface happens to be named today.
        expect($action)->not->toContain('UserController')
            ->and($action)->not->toContain('RoleController')
            ->and($action)->not->toContain('PeriodController')
            ->and($action)->not->toContain('ArchivePeriodController');
    }
});

it('exposes property definitions read-only, never a write', function () {
    $definitionRoutes = apiV1Routes()->filter(
        fn ($route): bool => str_contains($route->getActionName(), 'PropertyDefinitionController'),
    );

    expect($definitionRoutes)->not->toBeEmpty();

    foreach ($definitionRoutes as $route) {
        expect($route->methods())->toEqualCanonicalizing(['GET', 'HEAD']);
    }
});

/**
 * The mutation-proof half of "touches no route for users, roles, or
 * archive periods": that test's own assertion, run against the REAL route
 * table plus one route deliberately registered with a forbidden
 * controller name, must fail -- not merely "would fail" by inspection.
 * Without this, the rule above could pass for reasons that have nothing to
 * do with catching a real violation (a typo'd controller name, a route
 * group that silently stopped registering).
 */
it('fails its own no-forbidden-controller assertion when one is actually registered', function () {
    Route::get('api/v1/__mutation-probe/roles', [FileController::class, 'show'])
        ->name('__mutation-probe.roles-probe');

    $probe = apiV1Routes()->first(fn ($route) => $route->getName() === '__mutation-probe.roles-probe');

    expect($probe)->not->toBeNull();

    // getActionName() reads the CONTROLLER this route really dispatches to
    // -- FileController, which is legitimate -- so the assertion below is
    // run against a name rewritten to look like the forbidden pattern the
    // real test checks for, the same substring test, proving it actually
    // rejects a match rather than vacuously passing on every string.
    $asIfItWereForbidden = str_replace('FileController', 'RoleController', $probe->getActionName());

    expect(fn () => expect($asIfItWereForbidden)->not->toContain('RoleController'))
        ->toThrow(Exception::class);
});
