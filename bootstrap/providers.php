<?php

use App\Providers\AppServiceProvider;
use App\Providers\DoccumServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RuntimeConfigServiceProvider;

return [
    RuntimeConfigServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    DoccumServiceProvider::class,
];
