<?php

use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\FileVersionDownloadController;
use App\Livewire\Admin\PropertyDefinitions;
use App\Livewire\Files\Browser;
use App\Livewire\Search\Results;
use App\Livewire\Setup\FirstRun;
use Illuminate\Support\Facades\Route;

Route::livewire('/setup', FirstRun::class)->name('setup');

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::get('/files/{file}/download', FileDownloadController::class)
    ->middleware('auth')
    ->name('files.download');

// whereNumber('version') is load-bearing, not decoration. The controller
// takes {version} as an int rather than an implicit model binding, so that
// authorize() runs before the id is ever resolved (see the controller's
// docblock). Without this constraint a non-numeric segment reaches that int
// parameter and raises a TypeError -- a 500 where the caller should simply
// get a 404, and a differently-shaped response for a malformed id than for
// a well-formed one that does not exist.
Route::get('/files/{file}/versions/{version}/download', FileVersionDownloadController::class)
    ->whereNumber('version')
    ->middleware('auth')
    ->name('files.versions.download');

Route::livewire('/files/{directory?}', Browser::class)
    ->middleware('auth')
    ->name('files.browse');

Route::livewire('admin/properties', PropertyDefinitions::class)
    ->middleware(['auth', 'can:properties.manage'])
    ->name('admin.properties');

require __DIR__.'/settings.php';

Route::livewire('/search', Results::class)
    ->middleware('auth')
    ->name('search');
