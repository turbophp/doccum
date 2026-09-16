<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\AppliesTo;
use App\Enums\PropertyDataType;
use App\Livewire\Files\PropertyPanel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\PropertyDefinition;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->file = File::factory()->for($this->dir, 'directory')->create();
    $this->definition = PropertyDefinition::factory()->create([
        'key' => 'invoice_no', 'data_type' => PropertyDataType::String_,
    ]);
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

function allowOn(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id, 'grantee_type' => 'user',
        'grantee_id' => $user->id, 'level' => $level,
    ]);
}

it('refuses someone with no access to the file', function () {
    Livewire::actingAs($this->user)
        ->test(PropertyPanel::class, ['subject' => $this->file])
        ->assertForbidden();
});

it('refuses editing with only view access', function () {
    allowOn($this->dir, $this->user, AccessLevel::View);

    Livewire::actingAs($this->user)
        ->test(PropertyPanel::class, ['subject' => $this->file])
        ->set('values.invoice_no', 'ACME-001')
        ->call('save')
        ->assertForbidden();
});

it('saves with edit access', function () {
    allowOn($this->dir, $this->user, AccessLevel::Edit);

    Livewire::actingAs($this->user)
        ->test(PropertyPanel::class, ['subject' => $this->file])
        ->set('values.invoice_no', 'ACME-001')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->file->properties()->count())->toBe(1);
});

it('shows only definitions that apply to the subject', function () {
    PropertyDefinition::factory()->create([
        'key' => 'retention', 'label' => 'Retention period',
        'data_type' => PropertyDataType::String_, 'applies_to' => AppliesTo::Directory,
    ]);
    allowOn($this->dir, $this->user, AccessLevel::Edit);

    Livewire::actingAs($this->user)
        ->test(PropertyPanel::class, ['subject' => $this->file])
        ->assertSee('invoice_no')
        ->assertDontSee('Retention period');
});
