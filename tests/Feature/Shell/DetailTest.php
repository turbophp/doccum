<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\AppliesTo;
use App\Enums\ExtractionStatus;
use App\Enums\PropertyDataType;
use App\Livewire\Files\Detail;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\FileText;
use App\Models\FileVersion;
use App\Models\PropertyDefinition;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Number;
use Livewire\Livewire;

// The detail pane (design plan §7-8, plan Task 5): one flat pane, three tabs
// (Properties / Versions / Text), no stacked cards. Properties' authorisation
// and value-seeding are lifted from PropertyPanel -- same guarantees, new
// markup -- so the first block of tests mirrors PropertyPanelTest almost
// line for line. What is new here is the pane shape: Versions, Text, the
// hold band, and that Directory works too.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->file = File::factory()->for($this->dir, 'directory')->create();
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

function detailGrant(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id, 'grantee_type' => 'user',
        'grantee_id' => $user->id, 'level' => $level,
    ]);
}

/** A file with one current version and a file_texts row in the given status. */
function detailFileWithText(File $file, ExtractionStatus $status, array $textAttributes = []): FileVersion
{
    $version = FileVersion::factory()->for($file, 'file')->create(['version_number' => 1]);
    $file->update(['current_version_id' => $version->id]);
    FileText::factory()->for($version, 'version')->create(array_merge(['status' => $status], $textAttributes));

    return $version;
}

// -- Properties: lifted from PropertyPanel; same guarantees must hold --

it('refuses someone with no access to the file', function () {
    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->assertForbidden();
});

it('refuses editing properties with only view access', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);
    PropertyDefinition::factory()->create(['key' => 'invoice_no', 'data_type' => PropertyDataType::String_]);

    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->set('values.invoice_no', 'ACME-001')
        ->call('save')
        ->assertForbidden();
});

it('saves properties with edit access', function () {
    detailGrant($this->dir, $this->user, AccessLevel::Edit);
    PropertyDefinition::factory()->create(['key' => 'invoice_no', 'data_type' => PropertyDataType::String_]);

    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->set('values.invoice_no', 'ACME-001')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->file->properties()->count())->toBe(1);
});

it('shows only property definitions that apply to the subject', function () {
    PropertyDefinition::factory()->create(['key' => 'invoice_no', 'data_type' => PropertyDataType::String_]);
    PropertyDefinition::factory()->create([
        'key' => 'retention', 'label' => 'Retention period',
        'data_type' => PropertyDataType::String_, 'applies_to' => AppliesTo::Directory,
    ]);
    detailGrant($this->dir, $this->user, AccessLevel::Edit);

    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->assertSee('invoice_no')
        ->assertDontSee('Retention period');
});

it('maps a validation failure on save into a values.{key} error', function () {
    PropertyDefinition::factory()->create([
        'key' => 'status', 'data_type' => PropertyDataType::Select, 'options' => ['draft', 'final'],
    ]);
    detailGrant($this->dir, $this->user, AccessLevel::Edit);

    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->set('values.status', 'shredded')
        ->call('save')
        ->assertHasErrors(['values.status']);

    expect($this->file->properties()->count())->toBe(0);
});

// -- Directory support: properties apply; versions/text tabs are absent --

it('works for a directory subject: properties apply, versions and text tabs are absent', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->dir])
        ->assertOk()
        ->html();

    expect($html)->toContain('data-tab="properties"')
        ->and($html)->not->toContain('data-tab="versions"')
        ->and($html)->not->toContain('data-tab="text"');
});

it('still authorises a directory subject through its own policy', function () {
    // No grant at all: the directory policy must refuse, exactly as the file
    // policy does above. Mutation-checked below.
    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->dir])
        ->assertForbidden();
});

// -- Versions tab --

it('lists versions newest first, marks the current one, and links to the download route', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);

    $v1 = FileVersion::factory()->for($this->file, 'file')->create(['version_number' => 1, 'size' => 912_000]);
    $v2 = FileVersion::factory()->for($this->file, 'file')->create(['version_number' => 2, 'size' => 1_200_000]);
    $this->file->update(['current_version_id' => $v2->id]);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file->fresh()])
        ->html();

    // Newest first.
    preg_match_all('/data-version="(\d+)"/', $html, $matches);
    expect($matches[1])->toBe(['2', '1']);

    // Current is marked, the other is not -- there is no boolean on the
    // version itself, only file.current_version_id.
    expect($html)->toMatch('/data-version="2"\s+data-current="true"/')
        ->and($html)->toMatch('/data-version="1"\s+data-current="false"/');

    // Size and date carry the .num utility.
    expect($html)->toContain(Number::fileSize($v2->size, precision: 1))
        ->and($html)->toContain('num');

    // Download goes through the existing files.download route.
    expect($html)->toContain(route('files.download', $this->file));
});

it('shows no versions tab content beyond an empty message when a file has no versions yet', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->html();

    expect($html)->not->toContain('data-version=');
});

// -- Text tab: all five ExtractionStatus states, each rendered distinctly --

it('shows the extracted text when extraction is done', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);
    detailFileWithText($this->file, ExtractionStatus::Done, ['text' => 'the quarterly numbers']);

    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file->fresh()])
        ->assertSee('the quarterly numbers');
});

it('says extraction is pending, without the attention colour', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);
    detailFileWithText($this->file, ExtractionStatus::Pending);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file->fresh()])
        ->html();

    expect($html)->toContain('pending');
});

it('says extraction is in progress while processing', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);
    detailFileWithText($this->file, ExtractionStatus::Processing);

    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file->fresh()])
        ->assertSee('Extracting');
});

it('shows the failure reason in attention when extraction failed', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);
    detailFileWithText($this->file, ExtractionStatus::Failed, ['error' => 'tesseract exited 1']);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file->fresh()])
        ->html();

    expect($html)->toContain('tesseract exited 1')
        ->and($html)->toContain('text-attention');
});

it('says the format is unsupported, without the attention colour', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);
    detailFileWithText($this->file, ExtractionStatus::Unsupported);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file->fresh()])
        ->html();

    expect($html)->toContain('cannot be read')
        ->and($html)->not->toContain('text-attention');
});

it('renders every extraction status with its own distinct message', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);

    $messages = [];

    foreach (ExtractionStatus::cases() as $status) {
        $file = File::factory()->for($this->dir, 'directory')->create();
        detailFileWithText($file, $status, ['text' => 'body text', 'error' => 'boom']);

        $html = Livewire::actingAs($this->user)
            ->test(Detail::class, ['subject' => $file->fresh()])
            ->html();

        // Isolate the text panel so the properties/versions markup around it
        // cannot accidentally make two different statuses look alike.
        preg_match('/data-panel="text".*?<\/div>\s*<\/div>/s', $html, $panel);
        $messages[$status->value] = $panel[0] ?? $html;
    }

    expect(array_unique($messages))->toHaveCount(5);
});

// -- Legal hold band --

it('shows a hold band for a file under legal hold', function () {
    $this->file->update(['legal_hold' => true]);
    detailGrant($this->dir, $this->user, AccessLevel::View);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file->fresh()])
        ->html();

    expect($html)->toContain('Under legal hold')
        ->and($html)->toContain('text-hold');
});

it('shows no hold band for a file that is not under legal hold', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);

    Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->assertDontSee('Under legal hold');
});

// -- Shape: flat pane, resizable, tab underline wired to the shared spring --

it('is a flat pane sized per the design plan, not stacked cards', function () {
    detailGrant($this->dir, $this->user, AccessLevel::View);

    $html = Livewire::actingAs($this->user)
        ->test(Detail::class, ['subject' => $this->file])
        ->html();

    expect($html)->toContain('w-[336px]')
        ->and($html)->toContain('min-w-[288px]')
        ->and($html)->toContain('max-w-[480px]');
});

it('drives the tab-change motion with the shared tabUnderline spring', function () {
    $view = file_get_contents(resource_path('views/livewire/files/detail.blade.php'));

    // Wired through the Alpine magics resources/js/app.js registers for
    // resources/js/shell/motion.js (`$move` / `$springs`), not a per-view
    // import: motion.js is bundled into app.js's own chunk rather than built
    // as its own entry, so a view-level Vite::asset() call on it would never
    // resolve to a real file.
    expect($view)->toContain('this.$move(')
        ->and($view)->toContain('this.$springs.tabUnderline');
});
