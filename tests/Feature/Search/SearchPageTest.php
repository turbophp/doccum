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
