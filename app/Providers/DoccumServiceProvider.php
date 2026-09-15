<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Directory;
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
    }
}
