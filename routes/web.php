<?php

use App\Livewire\Setup\FirstRun;
use Illuminate\Support\Facades\Route;

Route::livewire('/setup', FirstRun::class)->name('setup');

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
