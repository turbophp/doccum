<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Enums\AccessLevel;
use App\Jobs\ExtractText;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use App\Services\Search;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

// Nothing here touches SearchIndex directly. Every other search test primed the
// index by hand, which hid a real bug: the production path built the projection
// row and never published it, so search returned nothing in a running instance
// while every test passed. This exercises only what an upload actually does.

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
    $this->dir = Directory::factory()->create(['name' => 'Contracts']);
});

function uploadDocument(string $name, string $body): File
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $body);

    $file = app(StoreFileVersion::class)->handle(test()->user, test()->dir, $path, $name, 'text/plain');
    ExtractText::dispatchSync($file->currentVersion);

    return $file->fresh();
}

it('finds a document by a word inside it, through the upload path alone', function () {
    uploadDocument('Lease.txt', 'the tenant shall maintain the premises in good repair');

    $hits = app(Search::class)->for($this->user, 'tenant');

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Lease.txt');
});

it('finds a document by its name', function () {
    uploadDocument('Quarterly-Report.txt', 'unrelated contents');

    expect(app(Search::class)->for($this->user, 'quarterly'))->toHaveCount(1);
});

it('stops finding a trashed document', function () {
    $file = uploadDocument('Lease.txt', 'the tenant shall maintain');
    expect(app(Search::class)->for($this->user, 'tenant'))->toHaveCount(1);

    $file->delete();

    expect(app(Search::class)->for($this->user, 'tenant'))->toBeEmpty();
});

it('does not show it to someone without access', function () {
    uploadDocument('Lease.txt', 'the tenant shall maintain');

    $stranger = User::factory()->create();
    $stranger->assignRole('member');

    expect(app(Search::class)->for($stranger, 'tenant'))->toBeEmpty();
});

it('shows it once access is granted', function () {
    uploadDocument('Lease.txt', 'the tenant shall maintain');

    $member = User::factory()->create();
    $member->assignRole('member');
    DirectoryGrant::create([
        'directory_id' => $this->dir->id, 'grantee_type' => 'user',
        'grantee_id' => $member->id, 'level' => AccessLevel::View,
    ]);

    expect(app(Search::class)->for($member, 'tenant'))->toHaveCount(1);
});
