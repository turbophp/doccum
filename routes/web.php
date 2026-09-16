<?php

use App\Http\Controllers\FileDownloadController;
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
