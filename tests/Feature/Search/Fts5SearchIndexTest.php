<?php

declare(strict_types=1);

use App\Models\Directory;
use App\Models\File;
use App\Models\SearchDocument;
use App\Search\SearchIndex;
use App\Services\SearchIndexer;

beforeEach(function () {
    $this->dir = Directory::factory()->create(['name' => 'Contracts']);
    $this->visible = [$this->dir->id];
});

function indexFile(string $name, string $body = '', ?Directory $in = null): File
{
    $file = File::factory()->for($in ?? test()->dir, 'directory')->create(['name' => $name]);
    $doc = app(SearchIndexer::class)->index($file->fresh());
    $doc->update(['body' => $body]);
    app(SearchIndex::class)->put($doc->fresh());

    return $file;
}

it('finds a document by a word in its body', function () {
    indexFile('Lease.pdf', 'the tenant shall maintain the premises');

    $hits = app(SearchIndex::class)->search('tenant', $this->visible);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Lease.pdf');
});

it('finds a document by its title', function () {
    indexFile('Quarterly-Report.pdf', 'unrelated body text');

    expect(app(SearchIndex::class)->search('quarterly', $this->visible))->toHaveCount(1);
});

it('returns nothing for a word that appears nowhere', function () {
    indexFile('Lease.pdf', 'the tenant shall maintain');

    expect(app(SearchIndex::class)->search('helicopter', $this->visible))->toBeEmpty();
});

it('ranks a closer match above a weaker one', function () {
    indexFile('A.pdf', 'tenant tenant tenant obligations of the tenant');
    indexFile('B.pdf', 'a single mention of tenant among much other text '.str_repeat('filler ', 60));

    expect(app(SearchIndex::class)->search('tenant', $this->visible)->first()->title)->toBe('A.pdf');
});

it('never returns a document outside the viewer reach', function () {
    $other = Directory::factory()->create();
    indexFile('Secret.pdf', 'tenant obligations', $other);
    indexFile('Mine.pdf', 'tenant obligations');

    $hits = app(SearchIndex::class)->search('tenant', $this->visible);

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->title)->toBe('Mine.pdf');
});

it('returns nothing at all when the viewer can reach nothing', function () {
    indexFile('Lease.pdf', 'tenant');

    expect(app(SearchIndex::class)->search('tenant', []))->toBeEmpty();
});

it('scopes to a period when asked', function () {
    $file = indexFile('Old.pdf', 'tenant');
    SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)
        ->update(['period_year' => 2020]);

    expect(app(SearchIndex::class)->search('tenant', $this->visible, ['period_year' => 2020]))->toHaveCount(1)
        ->and(app(SearchIndex::class)->search('tenant', $this->visible, ['period_year' => 2026]))->toBeEmpty();
});

it('forgets a removed document', function () {
    $file = indexFile('Lease.pdf', 'tenant');
    app(SearchIndex::class)->forget(
        SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->firstOrFail()
    );

    expect(app(SearchIndex::class)->search('tenant', $this->visible))->toBeEmpty();
});

it('survives punctuation and operators in a query', function () {
    // FTS5 has its own query language. Raw input must never reach it: a stray
    // quote is a syntax error in production, not a no-match.
    indexFile('Lease.pdf', 'the tenant shall maintain');

    foreach (['tenant"', 'tenant AND', '"', '(', 'tenant*', 'NEAR(', 'a OR b', '^tenant', 'tenant:'] as $query) {
        expect(fn () => app(SearchIndex::class)->search($query, $this->visible))
            ->not->toThrow(Exception::class);
    }
});

it('still finds the document when the query carries punctuation', function () {
    indexFile('Lease.pdf', 'the tenant shall maintain');

    expect(app(SearchIndex::class)->search('tenant!', $this->visible))->toHaveCount(1)
        ->and(app(SearchIndex::class)->search('  tenant  ', $this->visible))->toHaveCount(1);
});

it('treats an empty query as no search rather than everything', function () {
    indexFile('Lease.pdf', 'tenant');

    expect(app(SearchIndex::class)->search('', $this->visible))->toBeEmpty()
        ->and(app(SearchIndex::class)->search('   ', $this->visible))->toBeEmpty()
        ->and(app(SearchIndex::class)->search('!!!', $this->visible))->toBeEmpty();
});
