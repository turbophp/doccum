<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\PropertyDataType;
use App\Livewire\Search\Results;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\SearchDocument;
use App\Models\User;
use App\Search\SearchIndex;
use App\Search\Terms;
use App\Services\SearchIndexer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create(['name' => 'Contracts']);
    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'Lease.pdf']);
    $doc = app(SearchIndexer::class)->index($file->fresh());
    $doc->update(['body' => 'the tenant shall maintain the premises in good repair']);
    app(SearchIndex::class)->put($doc->fresh());
});

function grantView(Directory $dir, User $user): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id, 'grantee_type' => 'user',
        'grantee_id' => $user->id, 'level' => AccessLevel::View,
    ]);
}

it('shows nothing before a query is typed', function () {
    grantView($this->dir, $this->user);

    Livewire::actingAs($this->user)->test(Results::class)
        ->assertDontSee('Lease.pdf');
});

it('finds a document the user may reach', function () {
    grantView($this->dir, $this->user);

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->assertSee('Lease.pdf');
});

it('shows nothing to a user without access, not a permission error', function () {
    // Telling someone a matching document exists but they may not open it is
    // itself a disclosure. They should see an ordinary empty result.
    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->assertDontSee('Lease.pdf')
        ->assertSee('No results');
});

it('shows the passage that matched, with the term marked inside it', function () {
    grantView($this->dir, $this->user);

    // The words either side of the match are what make a result readable --
    // before snippet() the page showed each document's first 200 characters,
    // so it said WHAT matched and never WHY. The term itself comes back
    // wrapped, so the phrase is no longer contiguous in the HTML: that is the
    // point, and asserting the surrounding words plus the marked term says so
    // more precisely than asserting the raw phrase ever did.
    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->assertSee('shall maintain')
        ->assertSee('<mark class="rounded-sm bg-attention/20 px-0.5 text-ink">tenant</mark>', false);
});

it('requires a signed-in user', function () {
    User::factory()->create(); // instance is set up
    $this->get(route('search'))->assertRedirect(route('login'));
});

it('lets a signed-in user open the page', function () {
    $this->actingAs($this->user)->get(route('search'))->assertOk();
});

it('narrows results with a mime filter, applied inside the query', function () {
    grantView($this->dir, $this->user);

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->assertSee('Lease.pdf')
        ->set('mime', 'text/plain')
        ->assertDontSee('Lease.pdf')
        ->set('mime', 'application/pdf')
        ->assertSee('Lease.pdf');
});

it('narrows results with a period filter, applied inside the query', function () {
    grantView($this->dir, $this->user);

    SearchDocument::where('subject_type', 'file')->where('title', 'Lease.pdf')
        ->update(['period_year' => 2024]);

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->set('periodYear', '2020')
        ->assertDontSee('Lease.pdf')
        ->set('periodYear', '2024')
        ->assertSee('Lease.pdf');
});

it('narrows results with a property filter, on its typed column', function () {
    grantView($this->dir, $this->user);

    $definition = PropertyDefinition::factory()->create([
        'key' => 'amount', 'label' => 'Amount', 'data_type' => PropertyDataType::Number,
    ]);
    $file = File::where('name', 'Lease.pdf')->first();
    Property::for($file, $definition)->setValue(100);
    app(SearchIndexer::class)->index($file->fresh());

    // index() rebuilds the projection from scratch -- extracted text plus
    // flattened properties -- which just replaced the body carrying "tenant"
    // set in beforeEach. Restore it, the same way indexFile() does elsewhere
    // in this suite, so the query word still matches.
    $doc = SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->first();
    $doc->update(['body' => 'the tenant shall maintain the premises in good repair']);
    app(SearchIndex::class)->put($doc->fresh());

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->set('propertyDefinitionId', (string) $definition->id)
        ->set('propertyValue', '999')
        ->assertDontSee('Lease.pdf')
        ->set('propertyValue', '100')
        ->assertSee('Lease.pdf');
});

it('never lets a property filter surface a document outside the viewer reach', function () {
    // Deliberately NOT granted: the file above belongs to $this->dir, which
    // this test never calls grantView() for. A property filter that built
    // its own query -- rather than ANDing onto the permission filter
    // Search::for() resolves -- would find it anyway.
    $definition = PropertyDefinition::factory()->create([
        'key' => 'amount', 'label' => 'Amount', 'data_type' => PropertyDataType::Number,
    ]);
    $file = File::where('name', 'Lease.pdf')->first();
    Property::for($file, $definition)->setValue(100);
    app(SearchIndexer::class)->index($file->fresh());

    $doc = SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->first();
    $doc->update(['body' => 'the tenant shall maintain the premises in good repair']);
    app(SearchIndex::class)->put($doc->fresh());

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->set('propertyDefinitionId', (string) $definition->id)
        ->set('propertyValue', '100')
        ->assertDontSee('Lease.pdf')
        ->assertSee('No results');
});

it('escapes hostile markup in the results page snippet before swapping the match into a mark element', function () {
    // A SEPARATE document from the one in beforeEach(): that fixture body has
    // no '<', '&' or quote anywhere, so it cannot tell escape-then-swap
    // (safe) apart from swap-then-escape or no escaping at all (both
    // dangerous, since the passage comes from an uploaded file an uploader
    // chooses the bytes of).
    $hostileDir = Directory::factory()->create(['name' => 'Hostile']);
    grantView($hostileDir, $this->user);

    $hostileFile = File::factory()->for($hostileDir, 'directory')->create(['name' => 'Hostile.pdf']);
    $hostileDoc = app(SearchIndexer::class)->index($hostileFile->fresh());
    $hostileDoc->update(['body' => 'the <b>tenant</b> shall & "maintain" the premises']);
    app(SearchIndex::class)->put($hostileDoc->fresh());

    $component = Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant');

    // Catches "never escapes" (deleting the e() call around the snippet
    // entirely): with no escaping at all, the document's own <b> tag would
    // render as a real element and this literal, escaped form would be
    // absent from the response.
    $component->assertSee('&lt;b&gt;', false);

    // Same failure mode from the other side: with no escaping, the raw tag
    // text below WOULD appear verbatim in the response. Asserting only the
    // positive form above would not by itself rule out the raw form also
    // being present (e.g. from a swap that duplicated it).
    $component->assertDontSee('<b>', false);

    // Catches "swap-then-escape" (escaping the WHOLE string after the
    // private-use markers were already swapped for <mark>...</mark>): in
    // that order the <mark> tags are themselves literal text by the time
    // escaping runs, so they would come back as "&lt;mark ...&gt;" instead
    // of a real element. Asserting only the escaped-<b> checks above would
    // not catch this, because swap-then-escape still escapes the document's
    // own markup correctly -- it only breaks the highlight.
    $component->assertSee('<mark class="rounded-sm bg-attention/20 px-0.5 text-ink">tenant</mark>', false);
});

// --- partial words ----------------------------------------------------------
//
// Terms are prefixes on SQLite now. The gap this closes ran the wrong way
// round: LikeSearchIndex, which MySQL and PostgreSQL use, has always matched
// %word%, so a partial word found documents there and nothing on SQLite --
// the embedded default that every single-container install runs.

it('finds a document from the start of a word', function () {
    grantView($this->dir, $this->user);

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'ten')
        ->assertSee('Lease.pdf');
});

it('still finds the whole word, so a complete query is not made worse', function () {
    grantView($this->dir, $this->user);

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->assertSee('Lease.pdf');
});

it('does not treat one or two letters as the start of a word', function () {
    // A prefix that short matches most of the vocabulary, which is not a
    // search: it would return every document containing any word beginning
    // with those letters, ranked by a score computed over noise.
    expect(Terms::toFts5('a'))->toBe('"a"')
        ->and(Terms::toFts5('te'))->toBe('"te"')
        ->and(Terms::toFts5('ten'))->toBe('"ten"*');
});

it('makes every word in a phrase a prefix, not just the last', function () {
    // People type a whole query and press enter here rather than searching as
    // they type, so the first words are as likely to be abbreviated as the
    // last one.
    expect(Terms::toFts5('supply agreement'))->toBe('"supply"* "agreement"*');
});
