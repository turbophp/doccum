<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Search\Palette;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Search\SearchHit;
use App\Search\SearchIndex;
use App\Services\SearchIndexer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

// The palette is a second way to reach search, so the question it has to answer
// is whether it is also a second way to reach things you cannot see. It is not:
// every query goes through Services\Search, which resolves the viewer's reach
// per query, and the palette adds no query of its own.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->mine = Directory::factory()->create(['name' => 'Mine']);
    $this->theirs = Directory::factory()->create(['name' => 'Theirs']);

    grant($this->mine, $this->user, AccessLevel::View);
});

it('stays shut, and searches nothing, until it is opened', function () {
    Livewire::actingAs($this->user)->test(Palette::class)
        ->assertSet('open', false)
        ->set('query', 'anything')
        ->assertSet('open', false);

    expect(Livewire::actingAs($this->user)->test(Palette::class)->instance()->hits())->toBeEmpty();
});

it('waits for a second character before querying', function () {
    // One letter against a body of scanned documents is not a search, it is a
    // full scan that happens to be spelled like one.
    $component = Livewire::actingAs($this->user)->test(Palette::class)
        ->call('openPalette')
        ->set('query', 'a');

    expect($component->instance()->hits())->toBeEmpty();
});

it('never returns a document outside the viewer\'s reach', function () {
    $mine = File::factory()->for($this->mine, 'directory')->create(['name' => 'Reachable.pdf']);
    File::factory()->for($this->theirs, 'directory')->create(['name' => 'Reachable-too.pdf']);

    app(SearchIndexer::class)->index($mine);

    $hits = Livewire::actingAs($this->user)->test(Palette::class)
        ->call('openPalette')
        ->set('query', 'Reachable')
        ->instance()
        ->hits();

    expect($hits->pluck('title')->all())->not->toContain('Reachable-too.pdf');
});

it('sends a file to its own preview, and a folder to its listing', function () {
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'Lease.pdf']);

    $palette = Livewire::actingAs($this->user)->test(Palette::class)->instance();

    $fileHit = new SearchHit('file', $file->id, $file->name, '', 0.0, $this->mine->id);
    $dirHit = new SearchHit('directory', $this->mine->id, $this->mine->name, '', 0.0, $this->mine->id);

    expect($palette->destinationFor($fileHit))
        ->toBe(route('files.browse', ['directory' => $this->mine->id, 'file' => $file->id]))
        ->and($palette->destinationFor($dirHit))
        ->toBe(route('files.browse', $this->mine));
});

it('produces no destination for a hit whose subject has gone', function () {
    // A result can outlive its row: something trashed between the index being
    // written and the palette being opened. A link to nothing is worse than no
    // link, so the view renders none.
    $palette = Livewire::actingAs($this->user)->test(Palette::class)->instance();

    expect($palette->destinationFor(new SearchHit('file', 99999, 'Gone.pdf', '', 0.0, null)))->toBeNull();
});

it('escapes hostile markup in the palette snippet before swapping the match into a mark element', function () {
    // A document distinct from any other fixture in this file: its body
    // carries markup adjacent to the searched term, which is the only way to
    // tell escape-then-swap (safe) apart from swap-then-escape or no
    // escaping at all (both dangerous) -- the passage comes from an
    // uploaded file, so an uploader chooses its bytes.
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'Hostile.pdf']);
    $doc = app(SearchIndexer::class)->index($file->fresh());
    $doc->update(['body' => 'the <b>tenant</b> shall & "maintain" the premises']);
    app(SearchIndex::class)->put($doc->fresh());

    $component = Livewire::actingAs($this->user)->test(Palette::class)
        ->call('openPalette')
        ->set('query', 'tenant');

    // Catches "never escapes" (deleting the e() call around the snippet
    // entirely): with no escaping at all, the document's own <b> tag would
    // render as a real element and this literal, escaped form would be
    // absent from the response.
    $component->assertSee('&lt;b&gt;', false);

    // Same failure mode from the other side: with no escaping, the raw tag
    // text below WOULD appear verbatim in the response.
    $component->assertDontSee('<b>', false);

    // Catches "swap-then-escape" (escaping the WHOLE string after the
    // private-use markers were already swapped for <mark>...</mark>): in
    // that order the <mark> tags are themselves literal text by the time
    // escaping runs, so they would come back as "&lt;mark ...&gt;" instead
    // of a real element. The escaped-<b> checks above would not catch this,
    // because swap-then-escape still escapes the document's own markup
    // correctly -- it only breaks the highlight.
    $component->assertSee('<mark class="rounded-sm bg-attention/20 px-0.5 text-ink">tenant</mark>', false);
});

it('forgets the query when it closes', function () {
    Livewire::actingAs($this->user)->test(Palette::class)
        ->call('openPalette')
        ->set('query', 'lease')
        ->call('closePalette')
        ->assertSet('open', false)
        ->assertSet('query', '');
});
