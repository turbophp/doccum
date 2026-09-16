<?php

declare(strict_types=1);

use App\Enums\StorageProvider;
use App\Livewire\Setup\FirstRun;
use App\Services\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('defaults to embedded storage', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->assertSet('storage_provider', StorageProvider::Embedded->value);
});

it('accepts embedded without asking for credentials', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->set('storage_provider', StorageProvider::Embedded->value)
        ->call('saveStorage')
        ->assertHasNoErrors()
        ->assertSet('step', 3);

    expect(app(Settings::class)->get('storage.provider'))->toBe('embedded');
});

it('derives the endpoint for a chosen provider', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->set('storage_provider', StorageProvider::R2->value)
        ->set('s3_account', 'abc123')
        ->set('s3_key', 'k')
        ->set('s3_secret', 'sssssss')
        ->set('s3_bucket', 'papers')
        ->call('previewEndpoint')
        ->assertSet('s3_endpoint', 'https://abc123.r2.cloudflarestorage.com');
});

it('stores the secret encrypted, never in the runtime file', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->set('storage_provider', StorageProvider::Embedded->value)
        ->call('saveStorage');

    expect(app(Settings::class)->isSecret('storage.secret'))->toBeFalse();
});
