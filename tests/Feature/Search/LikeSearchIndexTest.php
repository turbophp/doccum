<?php

declare(strict_types=1);

use App\Enums\PropertyDataType;
use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\SearchDocument;
use App\Search\LikeSearchIndex;
use App\Services\SearchIndexer;

// Resolved directly, not through the SearchIndex interface: DoccumServiceProvider
// only binds this class when the driver is not sqlite, and the test suite's
// default connection IS sqlite. Every filter this item adds must work
// identically on both implementations -- see CLAUDE.md's Seams table and
// item/search-filters' doneWhen -- so this exercises LikeSearchIndex's own
// logic (LIKE matching plus every filter) regardless of which one CI's sqlite
// leg happens to wire up for real traffic. The pgsql/mysql CI legs exercise
// this same class end to end, through the interface, for real.

beforeEach(function () {
    $this->dir = Directory::factory()->create(['name' => 'Contracts']);
    $this->visible = [$this->dir->id];
});

/** The projection row IS the index for this implementation, so there is nothing to `put()`. */
function likeIndexFile(string $name, string $body = '', ?Directory $in = null, array $attributes = []): File
{
    $file = File::factory()->for($in ?? test()->dir, 'directory')->create(['name' => $name, ...$attributes]);
    $doc = app(SearchIndexer::class)->index($file->fresh());
    $doc->update(['body' => $body]);

    return $file;
}

function likeIndexFileWithProperty(string $name, string $body, PropertyDefinition $definition, mixed $value, ?Directory $in = null): File
{
    $file = File::factory()->for($in ?? test()->dir, 'directory')->create(['name' => $name]);
    Property::for($file, $definition)->setValue($value);
    $doc = app(SearchIndexer::class)->index($file->fresh());
    $doc->update(['body' => $body]);

    return $file;
}

it('finds a document by a word in its body, via the LIKE fallback', function () {
    likeIndexFile('Lease.pdf', 'the tenant shall maintain the premises');

    $hits = app(LikeSearchIndex::class)->search('tenant', $this->visible);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Lease.pdf');
});

it('never returns a document outside the viewer reach, via the LIKE fallback', function () {
    $other = Directory::factory()->create();
    likeIndexFile('Secret.pdf', 'tenant obligations', $other);
    likeIndexFile('Mine.pdf', 'tenant obligations');

    $hits = app(LikeSearchIndex::class)->search('tenant', $this->visible);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Mine.pdf');
});

it('returns nothing at all when the viewer can reach nothing, via the LIKE fallback', function () {
    likeIndexFile('Lease.pdf', 'tenant');

    expect(app(LikeSearchIndex::class)->search('tenant', []))->toBeEmpty();
});

it('scopes to a period when asked, via the LIKE fallback', function () {
    $file = likeIndexFile('Old.pdf', 'tenant');
    SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)
        ->update(['period_year' => 2020]);

    expect(app(LikeSearchIndex::class)->search('tenant', $this->visible, ['period_year' => 2020]))->toHaveCount(1)
        ->and(app(LikeSearchIndex::class)->search('tenant', $this->visible, ['period_year' => 2026]))->toBeEmpty();
});

it('filters by mime type, inside the query, via the LIKE fallback', function () {
    likeIndexFile('Lease.pdf', 'tenant obligations', attributes: ['mime' => 'application/pdf']);
    likeIndexFile('Lease.txt', 'tenant obligations', attributes: ['mime' => 'text/plain']);

    $hits = app(LikeSearchIndex::class)->search('tenant', $this->visible, ['mime' => 'application/pdf']);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Lease.pdf');
});

it('filters by a property value on its typed column, not a string comparison, via the LIKE fallback', function () {
    $definition = PropertyDefinition::factory()->create([
        'key' => 'amount', 'label' => 'Amount', 'data_type' => PropertyDataType::Number,
    ]);
    likeIndexFileWithProperty('A.pdf', 'tenant obligations', $definition, 100);
    likeIndexFileWithProperty('B.pdf', 'tenant obligations', $definition, 250);

    $hits = app(LikeSearchIndex::class)->search('tenant', $this->visible, [
        'property_definition_id' => $definition->id,
        'property_value' => 250,
    ]);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('B.pdf');
});

it('never returns a property-filtered match outside the viewer reach, via the LIKE fallback', function () {
    $definition = PropertyDefinition::factory()->create([
        'key' => 'supplier', 'label' => 'Supplier', 'data_type' => PropertyDataType::String_,
    ]);
    $other = Directory::factory()->create();

    likeIndexFileWithProperty('Secret.pdf', 'tenant obligations', $definition, 'Acme', $other);
    likeIndexFileWithProperty('Mine.pdf', 'tenant obligations', $definition, 'Acme');

    $hits = app(LikeSearchIndex::class)->search('tenant', $this->visible, [
        'property_definition_id' => $definition->id,
        'property_value' => 'Acme',
    ]);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Mine.pdf');
});
