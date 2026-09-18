<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// The preview endpoint serves bytes INLINE, which the download endpoint
// deliberately does not. That difference is the whole reason it exists, and it
// brings a risk the download route never had: bytes rendered by the browser,
// same-origin with the application.

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->dir = Directory::factory()->create(['name' => 'Cases']);
    grant($this->dir, $this->user, AccessLevel::Manage);
});

function uploadFor(Directory $directory, User $user, string $name, string $body, string $mime): File
{
    Livewire::actingAs($user)
        ->test(Browser::class, ['directory' => $directory])
        ->set('upload', UploadedFile::fake()->createWithContent($name, $body))
        ->call('store')
        ->assertHasNoErrors();

    return File::query()->where('directory_id', $directory->getKey())->where('name', $name)->firstOrFail();
}

it('serves a text file inline, so a frame shows it rather than downloading it', function () {
    $file = uploadFor($this->dir, $this->user, 'note.txt', 'the contents', 'text/plain');

    $response = $this->actingAs($this->user)->get(route('files.preview', $file));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toStartWith('inline')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('serves uploaded HTML as plain text, never as HTML', function () {
    // The endpoint is same-origin with the application, so HTML rendered here
    // would run its own scripts with the viewer's session -- anyone who can
    // upload a file could act as anyone who previews it. The preview is for
    // looking at what a file contains, and markup shown as text is exactly
    // that.
    $file = uploadFor($this->dir, $this->user, 'page.html', '<script>alert(1)</script>', 'text/html');
    $file->currentVersion->update(['mime' => 'text/html']);

    $response = $this->actingAs($this->user)->get(route('files.preview', $file->fresh()));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toStartWith('text/plain')
        ->and($response->headers->get('content-type'))->not->toContain('text/html');
});

it('refuses to preview a file the viewer cannot reach', function () {
    $elsewhere = Directory::factory()->create(['name' => 'Theirs']);
    $stranger = User::factory()->create();
    $stranger->assignRole('member');
    grant($elsewhere, $stranger, AccessLevel::Manage);

    $file = uploadFor($elsewhere, $stranger, 'secret.txt', 'private', 'text/plain');

    $this->actingAs($this->user)
        ->get(route('files.preview', $file))
        ->assertForbidden();
});

it('answers 404 for a file with no stored version', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'empty.txt']);

    $this->actingAs($this->user)
        ->get(route('files.preview', $file))
        ->assertNotFound();
});

it('refuses a type it will not promise to render inline', function () {
    $file = uploadFor($this->dir, $this->user, 'archive.zip', 'PK', 'application/zip');
    $file->currentVersion->update(['mime' => 'application/zip']);

    $this->actingAs($this->user)
        ->get(route('files.preview', $file->fresh()))
        ->assertStatus(415);
});
