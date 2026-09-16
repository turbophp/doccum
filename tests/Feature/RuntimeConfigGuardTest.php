<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\RuntimeConfigServiceProvider;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(fn () => @unlink($this->file));

it('refuses to run the installer when config is present but unreadable', function () {
    file_put_contents($this->file, '{ not json');
    (new RuntimeConfigServiceProvider(app()))->applyOverrides();

    $this->get(route('setup'))
        ->assertServiceUnavailable()
        ->assertSee('APP_KEY', false);
});

it('does not render unrelated 503 messages to the public', function () {
    // Laravel hides abort() messages by default; the custom 503 view must not
    // undo that for anything other than the runtime-config lockout.
    User::factory()->create(); // instance is set up, so no setup redirect

    Route::get('/__boom', fn () => abort(503, 'a-secret-internal-detail'))
        ->middleware('web');

    $this->get('/__boom')
        ->assertServiceUnavailable()
        ->assertDontSee('a-secret-internal-detail')
        ->assertSee('Service Unavailable', false);
});
