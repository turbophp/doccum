<?php

declare(strict_types=1);

use App\Http\Controllers\DirectoryArchiveDownloadController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\FilePreviewController;
use App\Http\Controllers\FileVersionDownloadController;
use App\Livewire\Admin\InstanceSettings;
use App\Livewire\Admin\Periods;
use App\Livewire\Admin\PropertyDefinitions;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Users;
use App\Livewire\Files\Browser;
use App\Livewire\Home\Index as Home;
use App\Livewire\Search\Results;
use App\Livewire\Setup\FirstRun;
use App\Livewire\Trash\Index as Trash;
use Illuminate\Support\Facades\Route;

Route::livewire('/setup', FirstRun::class)->name('setup');

Route::view('/', 'welcome')->name('home');

// item/email-verification-decided (issue #161): the split this whole file
// now makes. Every route below that shows, moves or administers document
// CONTENT carries `verified` alongside `auth` -- a gate that let you browse,
// upload, download, search and administer everything except one dashboard
// tile was not a gate at all. `admin.*` routes carry `verified` ALONGSIDE
// their existing `can:` middleware, not instead of it -- both authorisation
// layers CLAUDE.md already requires (Spatie permission, directory_access)
// are orthogonal to whether the account itself has been vouched for.
//
// NOT gated, deliberately:
//   - routes/settings.php's `auth`-only group (profile, password): an
//     unverified user must still be able to reach these. It is where the
//     resend-verification banner lives (App\Livewire\Settings\Profile), and
//     where they fix an address they mistyped. Gating the one place that can
//     get them unstuck would BE the lockout this whole design exists to
//     avoid -- see that file's own comment at the same split.
//   - anything Fortify owns for authentication and verification itself
//     (login, registration, password reset, the verification notice/verify
//     routes) and /setup: none of those can require verification of the
//     very thing they exist to establish.
Route::middleware(['auth', 'verified'])->group(function () {
    // item/home-dashboard (issue #16): this was starter-kit scaffolding
    // (Route::view('dashboard', 'dashboard'), a static placeholder view with
    // three empty tiles) -- replaced with the real Home destination spec §10
    // names, kept on the SAME route name ('dashboard') so every existing
    // route('dashboard') caller (the topbar's own Home link, Profile's
    // post-save redirect, welcome.blade.php) needs no change at all.
    Route::livewire('dashboard', Home::class)->name('dashboard');

    // whereNumber('archive'): {archive} is an int rather than an implicit
    // model binding (item/reach-oracle-route-binding), so without this a
    // non-numeric segment reaches an int parameter and raises a TypeError --
    // a 500 where a 404 belongs, and a differently-shaped response for a
    // malformed id than for a well-formed one that does not exist.
    Route::get('/directories/archives/{archive}/download', DirectoryArchiveDownloadController::class)
        ->whereNumber('archive')
        ->name('directories.archives.download');

    // Separate from the download route because the two want opposite things
    // from a browser: download answers Content-Disposition: attachment and may
    // redirect to a presigned URL, neither of which a preview frame can use.
    // See FilePreviewController.
    // whereNumber('file') for the reason the {version} comment below gives,
    // now that {file} is an int rather than an implicit model binding
    // (item/reach-oracle-route-binding): without it a non-numeric segment
    // reaches an int parameter and raises a TypeError -- a 500 where the
    // caller should get a 404, and a differently-shaped response for a
    // malformed id than for a well-formed one that does not exist. That
    // shape difference is the same class of leak the item exists to close.
    Route::get('/files/{file}/preview', FilePreviewController::class)
        ->whereNumber('file')
        ->name('files.preview');

    Route::get('/files/{file}/download', FileDownloadController::class)
        ->whereNumber('file')
        ->name('files.download');

    // whereNumber('version') is load-bearing, not decoration. The controller
    // takes {version} as an int rather than an implicit model binding, so that
    // authorize() runs before the id is ever resolved (see the controller's
    // docblock). Without this constraint a non-numeric segment reaches that int
    // parameter and raises a TypeError -- a 500 where the caller should simply
    // get a 404, and a differently-shaped response for a malformed id than for
    // a well-formed one that does not exist.
    Route::get('/files/{file}/versions/{version}/download', FileVersionDownloadController::class)
        ->whereNumber('file')
        ->whereNumber('version')
        ->name('files.versions.download');

    Route::livewire('/files/{directory?}', Browser::class)
        ->name('files.browse');

    // '/trash' cannot collide with the parameterised '/files/{directory?}'
    // above -- they are different literal segments -- so there is nothing here
    // for route registration order to get wrong. See spec §10 ("Trash --
    // reached from Files") and issue #15.
    Route::livewire('/trash', Trash::class)
        ->name('trash');

    Route::livewire('admin/properties', PropertyDefinitions::class)
        ->middleware('can:properties.manage')
        ->name('admin.properties');

    Route::livewire('admin/users', Users::class)
        ->middleware('can:users.manage')
        ->name('admin.users');

    // item/admin-roles (issue #19): gated on the SAME permission as admin.users
    // above, not a new roles.manage -- see App\Livewire\Admin\Roles's own
    // docblock for why.
    Route::livewire('admin/roles', Roles::class)
        ->middleware('can:users.manage')
        ->name('admin.roles');

    // item/admin-periods (issue #20): its own permission, not users.manage --
    // closing and purging archive periods is a distinct administrative concern
    // from users and roles, and spec §10 lists "archive periods" as its own
    // Settings section.
    Route::livewire('admin/periods', Periods::class)
        ->middleware('can:periods.manage')
        ->name('admin.periods');

    // item/admin-instance-settings (issue #21): gated on the SAME permission as
    // admin.users and admin.roles, not a new settings.manage -- see
    // App\Livewire\Admin\InstanceSettings's own docblock for why.
    Route::livewire('admin/settings', InstanceSettings::class)
        ->middleware('can:users.manage')
        ->name('admin.settings');

    Route::livewire('/search', Results::class)
        ->name('search');
});

require __DIR__.'/settings.php';
