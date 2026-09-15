<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        //
    }

    public function boot(): void
    {
        //
    }
}
