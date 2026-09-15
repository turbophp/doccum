<?php

use App\Providers\AppServiceProvider;
use App\Providers\DoccumServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    DoccumServiceProvider::class,
];
