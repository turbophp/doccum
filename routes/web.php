<?php

use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\FileVersionDownloadController;
use App\Livewire\Admin\PropertyDefinitions;
use App\Livewire\Files\Browser;
use App\Livewire\Home\Index as Home;
use App\Livewire\Search\Results;
use App\Livewire\Setup\FirstRun;
use App\Livewire\Trash\Index as Trash;
use Illuminate\Support\Facades\Route;

Route::livewire('/setup', FirstRun::class)->name('setup');

Route::view('/', 'welcome')->name('home');

// item/home-dashboard (issue #16): this was starter-kit scaffolding
// (Route::view('dashboard', 'dashboard'), a static placeholder view with
// three empty tiles) -- replaced with the real Home destination spec §10
// names, kept on the SAME route name ('dashboard') so every existing
// route('dashboard') caller (the topbar's own Home link, Profile's
// post-save redirect, welcome.blade.php) needs no change at all.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', Home::class)->name('dashboard');
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

// '/trash' cannot collide with the parameterised '/files/{directory?}'
// above -- they are different literal segments -- so there is nothing here
// for route registration order to get wrong. See spec §10 ("Trash --
// reached from Files") and issue #15.
Route::livewire('/trash', Trash::class)
    ->middleware('auth')
    ->name('trash');

Route::livewire('admin/properties', PropertyDefinitions::class)
    ->middleware(['auth', 'can:properties.manage'])
    ->name('admin.properties');

require __DIR__.'/settings.php';

Route::livewire('/search', Results::class)
    ->middleware('auth')
    ->name('search');
