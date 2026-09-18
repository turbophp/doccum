<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Search\Palette;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Search\SearchHit;
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

it('forgets the query when it closes', function () {
    Livewire::actingAs($this->user)->test(Palette::class)
        ->call('openPalette')
        ->set('query', 'lease')
        ->call('closePalette')
        ->assertSet('open', false)
        ->assertSet('query', '');
});
