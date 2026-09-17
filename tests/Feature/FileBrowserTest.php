<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->mine = Directory::factory()->create(['name' => 'Mine']);
    $this->theirs = Directory::factory()->create(['name' => 'Theirs']);

    DirectoryGrant::create([
        'directory_id' => $this->mine->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::Manage,
    ]);
});

it('lists only directories the viewer may see', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class)
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

it('lists files in the current directory', function () {
    File::factory()->for($this->mine, 'directory')->create(['name' => 'Visible.pdf']);
    File::factory()->for($this->theirs, 'directory')->create(['name' => 'Hidden.pdf']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->assertSee('Visible.pdf')
        ->assertDontSee('Hidden.pdf');
});

it('refuses to open a directory the viewer cannot see', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->theirs])
        ->assertForbidden();
});

it('creates a subdirectory', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('newDirectoryName', '2024')
        ->call('createDirectory')
        ->assertHasNoErrors();

    expect(Directory::where('name', '2024')->value('parent_id'))->toBe($this->mine->id);
});

it('surfaces a duplicate name as a validation error', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('newDirectoryName', '2024')
        ->call('createDirectory')
        ->set('newDirectoryName', '2024')
        ->call('createDirectory')
        ->assertHasErrors('newDirectoryName');
});

it('uploads a file', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('upload', UploadedFile::fake()->create('Report.pdf', 12, 'application/pdf'))
        ->call('store')
        ->assertHasNoErrors();

    $file = File::where('name', 'Report.pdf')->firstOrFail();

    expect($file->directory_id)->toBe($this->mine->id)
        ->and(Storage::disk('documents')->exists($file->currentVersion->object_key))->toBeTrue();
});

it('refuses an upload without edit access', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    DirectoryGrant::create([
        'directory_id' => $this->mine->id,
        'grantee_type' => 'user',
        'grantee_id' => $viewer->id,
        'level' => AccessLevel::View,
    ]);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('upload', UploadedFile::fake()->create('Report.pdf', 12))
        ->call('store')
        ->assertForbidden();

    // Scoped to the file this test itself would have created, not the whole
    // table: File::count() breaks the moment anything else in the suite
    // legitimately writes a row (CLAUDE.md).
    expect(File::where('name', 'Report.pdf')->count())->toBe(0);
});
