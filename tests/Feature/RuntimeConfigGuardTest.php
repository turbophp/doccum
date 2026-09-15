<?php

declare(strict_types=1);

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
