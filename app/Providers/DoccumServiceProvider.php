<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

/**
 * The single registration point for doccum.
 *
 * Every macro, container binding, morph map, policy registration, and Blade
 * directive we add lives here, so auditing what this application changed about
 * Laravel during a framework upgrade is reading one file. See spec §3.
 */
class DoccumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DirectoryAccess::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'directory' => Directory::class,
            'file' => File::class,
        ]);

        // The memoisation in DirectoryAccess is only safe while nothing has
        // changed what a grant means. Because that service is a singleton, a
        // request that resolves access, writes a grant, then resolves again
        // would otherwise get the stale answer. Subtree membership is derived
        // from the materialised path, so a directory move invalidates access
        // answers just as much as a grant does. See DirectoryAccess::flush().
        $flushAccess = static fn (): null => tap(null, fn () => app(DirectoryAccess::class)->flush());

        DirectoryGrant::saved($flushAccess);
        DirectoryGrant::deleted($flushAccess);
        Directory::saved($flushAccess);
        Directory::deleted($flushAccess);
    }
}
