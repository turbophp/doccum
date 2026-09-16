<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use App\Search\SearchIndex;
use App\Services\Search;
use App\Services\SearchIndexer;
use Database\Seeders\RolesAndPermissionsSeeder;

function publish(Directory $dir, string $name, string $body = 'quarterly revenue analysis'): File
{
    $file = File::factory()->for($dir, 'directory')->create(['name' => $name]);
    $doc = app(SearchIndexer::class)->index($file->fresh());
    $doc->update(['body' => $body]);
    app(SearchIndex::class)->put($doc->fresh());

    return $file;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mine = Directory::factory()->create(['name' => 'Mine']);
    $this->theirs = Directory::factory()->create(['name' => 'Theirs']);
    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    publish($this->mine, 'Mine.pdf');
    publish($this->theirs, 'Theirs.pdf');
});

function letSee(Directory $dir, User $user): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id, 'grantee_type' => 'user',
        'grantee_id' => $user->id, 'level' => AccessLevel::View,
    ]);
}

it('returns nothing to a user with no grants', function () {
    expect(app(Search::class)->for($this->user, 'quarterly'))->toBeEmpty();
});

it('returns only what the user may reach', function () {
    letSee($this->mine, $this->user);

    $hits = app(Search::class)->for($this->user, 'quarterly');

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Mine.pdf');
});

it('inherits reach from an ancestor grant', function () {
    $child = Directory::factory()->for($this->mine, 'parent')->create();
    publish($child, 'Deep.pdf');
    letSee($this->mine, $this->user);

    expect(app(Search::class)->for($this->user, 'quarterly')->pluck('title'))
        ->toContain('Deep.pdf');
});

it('gives an admin everything', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect(app(Search::class)->for($admin, 'quarterly'))->toHaveCount(2);
});

it('returns nothing for an empty query rather than everything', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect(app(Search::class)->for($admin, '   '))->toBeEmpty();
});

it('stops showing a document once access is revoked', function () {
    letSee($this->mine, $this->user);
    expect(app(Search::class)->for($this->user, 'quarterly'))->toHaveCount(1);

    // Deleted through the model, which is how the application revokes a grant.
    // A mass delete (DirectoryGrant::query()->delete()) fires no model events,
    // so the resolver's per-request memo would not be invalidated by it --
    // real, but not a path the application takes.
    DirectoryGrant::each(fn (DirectoryGrant $grant) => $grant->delete());

    // Access is resolved per search, not cached into the index, so a
    // revocation takes effect on the next query rather than the next reindex.
    expect(app(Search::class)->for($this->user, 'quarterly'))->toBeEmpty();
});
