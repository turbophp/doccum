<?php

use App\Http\Controllers\FileDownloadController;
use App\Livewire\Files\Browser;
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

require __DIR__.'/settings.php';
