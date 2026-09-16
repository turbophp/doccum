<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Search\Results;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
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

it('shows the snippet that matched', function () {
    grantView($this->dir, $this->user);

    Livewire::actingAs($this->user)->test(Results::class)
        ->set('query', 'tenant')
        ->assertSee('tenant shall maintain');
});

it('requires a signed-in user', function () {
    User::factory()->create(); // instance is set up
    $this->get(route('search'))->assertRedirect(route('login'));
});

it('lets a signed-in user open the page', function () {
    $this->actingAs($this->user)->get(route('search'))->assertOk();
});
