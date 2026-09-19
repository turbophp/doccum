<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OpenApi\OpenApiSpecBuilder;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * item/openapi-reference (issue #25), spec §12: "an OpenAPI reference...
 * generated from route signatures... A test asserts the committed spec
 * matches the current routes, so a drifting API fails the build rather than
 * the reader."
 *
 * `php artisan doccum:openapi` regenerates docs/api/openapi.json in place.
 * `php artisan doccum:openapi --check` regenerates it in MEMORY and exits
 * non-zero if that differs from the committed file, printing which paths or
 * methods differ -- CI's own drift check, run instead of trusting a
 * developer to remember `--check` locally.
 *
 * Reads Route::getRoutes() -- the same source tests/Feature/Api/
 * RouteSurfaceTest.php and tests/Feature/Api/OpenApiContractTest.php read --
 * never routes/api.php's own text, for the identical reason RouteSurfaceTest
 * gives: a route file can say one thing and register another.
 *
 * All the actual route-to-OpenAPI-operation logic lives in
 * App\Support\OpenApi\OpenApiSpecBuilder, deliberately framework-free so it
 * can run unchanged wherever this item's report's scratchpad script runs it
 * too. This command's own job is narrow: turn real Route objects into the
 * plain-array shape that class expects, and either write or diff the result.
 */
class GenerateOpenApi extends Command
{
    protected $signature = 'doccum:openapi {--check : Do not write the file; fail if it would change.}';

    protected $description = 'Generate docs/api/openapi.json from the live /api/v1 route table';

    public function handle(): int
    {
        $path = base_path('docs/api/openapi.json');

        $document = OpenApiSpecBuilder::build($this->routeDescriptors());
        $generated = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if (! $this->option('check')) {
            file_put_contents($path, $generated);
            $this->components->info('Wrote docs/api/openapi.json.');

            return self::SUCCESS;
        }

        // file_get_contents() returns string|false, and printPathDiff()
        // takes ?string -- an unreadable file and an absent one are the
        // same thing to a diff, so both become null rather than letting
        // `false` travel as if it were content.
        $committedRaw = is_file($path) ? file_get_contents($path) : false;
        $committed = $committedRaw === false ? null : $committedRaw;

        if ($committed === $generated) {
            $this->components->info('docs/api/openapi.json matches the live route table.');

            return self::SUCCESS;
        }

        $this->components->error('docs/api/openapi.json is out of date. Run `php artisan doccum:openapi` and commit the result.');
        $this->printPathDiff($committed, $generated);

        return self::FAILURE;
    }

    /**
     * Every route registered under the `api.v1.` name prefix, reduced to the
     * plain-array shape OpenApiSpecBuilder::build() takes. Mirrors
     * tests/Feature/Api/RouteSurfaceTest.php's own apiV1Routes(): filtered by
     * the URI Laravel actually registered ('api/v1/...'), not by name alone,
     * so a route that carries the prefix without the name (or vice versa)
     * cannot slip past either this generator or that test the same way.
     *
     * @return list<array{name: string, uri: string, methods: list<string>, abilities: list<string>}>
     */
    private function routeDescriptors(): array
    {
        $descriptors = [];

        // ->getRoutes(), not the collection itself: Route::getRoutes() is
        // typed RouteCollectionInterface, which is a plain interface -- it
        // does not extend IteratorAggregate, even though the concrete
        // RouteCollection does. foreach over the interface type is
        // therefore unsound, and the interface's own getRoutes() is
        // declared to return Illuminate\Routing\Route[].
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->getName() === null) {
                continue;
            }

            $descriptors[] = [
                'name' => $route->getName(),
                'uri' => $route->uri(),
                'methods' => array_values($route->methods()),
                'abilities' => $this->abilitiesFor($route),
            ];
        }

        // Stable order -- the same route table produces byte-identical
        // output every run, which is what makes --check a meaningful diff
        // rather than a coin flip on Route::getRoutes()'s own iteration order.
        usort($descriptors, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $descriptors;
    }

    /**
     * The `ability:...` middleware a route carries, in registration order --
     * routes/api.php's trash endpoints carry two (files and directories both),
     * which EnsureTokenAbility checks as two independent, ANDed gates. See
     * App\Enums\ApiTokenAbility's own docblock for the fixed scope list this
     * always narrows to.
     *
     * @return list<string>
     */
    private function abilitiesFor(RoutingRoute $route): array
    {
        $abilities = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (str_starts_with($middleware, 'ability:')) {
                $abilities[] = substr($middleware, strlen('ability:'));
            }
        }

        return $abilities;
    }

    private function printPathDiff(?string $committed, string $generated): void
    {
        $before = $this->pathMethodSet($committed);
        $after = $this->pathMethodSet($generated);

        foreach (array_diff($after, $before) as $added) {
            $this->line("  + {$added}");
        }

        foreach (array_diff($before, $after) as $removed) {
            $this->line("  - {$removed}");
        }

        if ($before === $after && $committed !== $generated) {
            $this->line('  (paths and methods match; a schema, summary or other detail differs)');
        }
    }

    /** @return list<string> */
    private function pathMethodSet(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        /** @var array{paths?: array<string, array<string, mixed>>} $decoded */
        $decoded = json_decode($json, true) ?? [];
        $set = [];

        foreach ($decoded['paths'] ?? [] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $set[] = strtoupper((string) $method).' '.$path;
            }
        }

        sort($set);

        return $set;
    }
}
