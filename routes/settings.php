<?php

use App\Livewire\Settings\ApiTokens;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use Illuminate\Support\Facades\Route;

// item/email-verification-decided (issue #161): deliberately `auth` alone,
// not `verified` -- the other half of the split routes/web.php's own
// comment describes. An unverified user must still reach this page: it is
// where App\Livewire\Settings\Profile's resend-verification banner lives,
// and where they fix an email address they mistyped at registration.
// Gating the one place that can get an unverified account unstuck would BE
// the lockout this whole design exists to avoid.
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');

    Route::livewire('settings/security', Security::class)
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');

    // Same password.confirm gate as Security above: minting a personal
    // access token hands out a new standing credential to whoever holds the
    // plaintext, which is at least as sensitive as enabling 2FA or adding a
    // passkey -- both gated the same way on this same page group.
    Route::livewire('settings/api-tokens', ApiTokens::class)
        ->middleware([
            'password.confirm',
        ])
        ->name('api-tokens.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
