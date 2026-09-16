<?php

declare(strict_types=1);

use App\Livewire\Admin\PropertyDefinitions;
use App\Models\PropertyDefinition;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->member = User::factory()->create();
    $this->member->assignRole('member');
});

it('lets an admin create a definition', function () {
    Livewire::actingAs($this->admin)
        ->test(PropertyDefinitions::class)
        ->set('key', 'invoice_no')
        ->set('label', 'Invoice number')
        ->set('data_type', 'string')
        ->call('save')
        ->assertHasNoErrors();

    expect(PropertyDefinition::where('key', 'invoice_no')->exists())->toBeTrue();
});

it('refuses a member', function () {
    Livewire::actingAs($this->member)
        ->test(PropertyDefinitions::class)
        ->assertForbidden();
});

it('rejects a duplicate key with a validation error, not an exception', function () {
    PropertyDefinition::factory()->create(['key' => 'invoice_no']);

    Livewire::actingAs($this->admin)
        ->test(PropertyDefinitions::class)
        ->set('key', 'invoice_no')
        ->set('label', 'Invoice number')
        ->set('data_type', 'string')
        ->call('save')
        ->assertHasErrors('key');
});

it('requires options for a select', function () {
    Livewire::actingAs($this->admin)
        ->test(PropertyDefinitions::class)
        ->set('key', 'status')
        ->set('label', 'Status')
        ->set('data_type', 'select')
        ->set('options_text', '')
        ->call('save')
        ->assertHasErrors('options_text');
});

it('normalises a key to the allowed character set', function () {
    Livewire::actingAs($this->admin)
        ->test(PropertyDefinitions::class)
        ->set('key', 'Invoice Number!')
        ->set('label', 'Invoice number')
        ->set('data_type', 'string')
        ->call('save')
        ->assertHasNoErrors();

    expect(PropertyDefinition::first()->key)->toBe('invoice_number');
});
