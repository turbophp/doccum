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

it('neutralises uploaded HTML with a sandbox policy rather than rendering it as the app', function () {
    // This endpoint serves somebody's uploaded bytes, same-origin, for a
    // browser to render. Left alone, an uploaded page would run its own
    // scripts with the viewer's session -- in a system built around people
    // filing documents for each other, that is one upload away from acting as
    // whoever opens it.
    //
    // The earlier answer was to serve HTML as text/plain, which was safe and
    // made the preview useless for the one type people most want to look at.
    // `sandbox` with no allow-scripts is the stronger answer: the document
    // still renders, in an opaque origin, with its scripts inert. The header
    // holds however the response is loaded, including a direct navigation --
    // which an iframe's own sandbox attribute does not cover.
    $file = uploadFor($this->dir, $this->user, 'page.html', '<script>alert(1)</script>', 'text/html');
    $file->currentVersion->update(['mime' => 'text/html']);

    $response = $this->actingAs($this->user)->get(route('files.preview', $file->fresh()));

    $response->assertOk();

    $policy = $response->headers->get('content-security-policy');

    expect($policy)->toContain('sandbox')
        ->and($policy)->not->toContain('allow-scripts')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('allows a Word document through, for the browser to convert', function () {
    // Nothing renders a .docx natively; the preview fetches these bytes and
    // converts them client-side. The endpoint's job is to let them through
    // rather than answer 415, which it did before Word was supported.
    $file = uploadFor($this->dir, $this->user, 'minutes.docx', 'PK stub', 'application/octet-stream');
    $file->currentVersion->update([
        'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('files.preview', $file->fresh()))
        ->assertOk();

    // The sandbox policy is for markup, and a .docx is not markup -- the
    // controller's own comment says it "must NOT be sent for anything else".
    // It was sent anyway: isMarkup() asked str_contains($mime, 'xml'), and
    // the OOXML type carries "xml" inside "openXMLformats". Nothing rendered
    // wrong, because these bytes are fetched and converted client-side
    // rather than loaded as a document -- which is precisely why asserting
    // only assertOk() here left it invisible.
    expect($response->headers->get('content-security-policy'))->toBeNull();
});

it('sandboxes markup by structure, not by a substring of the media type', function () {
    // The companion to the assertion above, from the other side: a type
    // whose SUBTYPE really is markup still gets the policy, so the tighter
    // predicate cannot have bought its precision by under-matching.
    $file = uploadFor($this->dir, $this->user, 'diagram.svg', '<svg/>', 'image/svg+xml');
    // uploadFor()'s $mime argument is not what the stored version ends up
    // carrying -- it only names the fake upload -- so the type under test is
    // set explicitly here, the same way every other case in this file does it.
    $file->currentVersion->update(['mime' => 'image/svg+xml']);

    $response = $this->actingAs($this->user)
        ->get(route('files.preview', $file->fresh()))
        ->assertOk();

    expect($response->headers->get('content-security-policy'))
        ->toContain('sandbox')
        ->not->toContain('allow-scripts');
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

it('does not sandbox a PDF, because the sandbox is what blanked it', function () {
    // `sandbox` disables plugins, and the browser's built-in PDF viewer is
    // one. Sending the header for every type served a perfectly valid PDF
    // into an empty frame -- the response was 200 with the right bytes and
    // the right content type, and the dialog showed nothing, which is the
    // hardest kind of broken to read from a test suite.
    //
    // A PDF already renders inside the browser's own sandbox, so the header
    // bought nothing here and cost the whole feature.
    $file = uploadFor($this->dir, $this->user, 'contract.pdf', '%PDF-1.4 stub', 'application/pdf');
    $file->currentVersion->update(['mime' => 'application/pdf']);

    $response = $this->actingAs($this->user)->get(route('files.preview', $file->fresh()));

    $response->assertOk();

    expect($response->headers->get('content-security-policy'))->toBeNull()
        // The protections that cost nothing are still there.
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->headers->get('content-disposition'))->toStartWith('inline');
});
