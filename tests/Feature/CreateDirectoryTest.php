<?php

declare(strict_types=1);

use App\Actions\Directories\CreateDirectory;
use App\Exceptions\DuplicateDirectoryName;
use App\Models\Directory;
use App\Models\User;

beforeEach(fn () => $this->user = User::factory()->create());

it('creates a root directory', function () {
    $dir = app(CreateDirectory::class)->handle($this->user, 'Invoices');

    expect($dir->name)->toBe('Invoices')
        ->and($dir->parent_id)->toBeNull()
        ->and($dir->created_by)->toBe($this->user->id)
        ->and($dir->path)->toBe("/{$dir->id}/");
});

it('creates a child directory', function () {
    $parent = app(CreateDirectory::class)->handle($this->user, 'Invoices');
    $child = app(CreateDirectory::class)->handle($this->user, '2024', $parent);

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->path)->toBe("/{$parent->id}/{$child->id}/")
        ->and($child->depth)->toBe(1);
});

it('refuses a duplicate name among live siblings', function () {
    app(CreateDirectory::class)->handle($this->user, 'Invoices');

    expect(fn () => app(CreateDirectory::class)->handle($this->user, 'Invoices'))
        ->toThrow(DuplicateDirectoryName::class);
});

it('refuses a name that differs only by case from a live sibling', function () {
    app(CreateDirectory::class)->handle($this->user, 'Invoices');

    expect(fn () => app(CreateDirectory::class)->handle($this->user, 'invoices'))
        ->toThrow(DuplicateDirectoryName::class);
});

it('allows a name that differs by an accent from a live sibling', function () {
    app(CreateDirectory::class)->handle($this->user, "R\u{00E9}sum\u{00E9}s"); // NFC "Résumés"

    $second = app(CreateDirectory::class)->handle($this->user, 'Resumes');

    expect($second->name)->toBe('Resumes');
});

it('refuses a name that reappears in a different unicode normalisation form', function () {
    $nfc = "R\u{00E9}sum\u{00E9}s"; // precomposed U+00E9
    $nfd = "Re\u{0301}sume\u{0301}s"; // "e" + combining acute U+0301

    app(CreateDirectory::class)->handle($this->user, $nfc);

    expect(fn () => app(CreateDirectory::class)->handle($this->user, $nfd))
        ->toThrow(DuplicateDirectoryName::class);
});

it('allows the same name under a different parent', function () {
    $a = app(CreateDirectory::class)->handle($this->user, 'A');
    $b = app(CreateDirectory::class)->handle($this->user, 'B');

    app(CreateDirectory::class)->handle($this->user, 'Shared', $a);
    $second = app(CreateDirectory::class)->handle($this->user, 'Shared', $b);

    expect($second->parent_id)->toBe($b->id);
});

it('allows reusing the name of a trashed sibling', function () {
    $first = app(CreateDirectory::class)->handle($this->user, 'Invoices');
    $first->delete();

    $second = app(CreateDirectory::class)->handle($this->user, 'Invoices');

    expect($second->id)->not->toBe($first->id)
        ->and(Directory::withTrashed()->where('name', 'Invoices')->count())->toBe(2);
});

it('trims the name', function () {
    expect(app(CreateDirectory::class)->handle($this->user, '  Invoices  ')->name)->toBe('Invoices');
});

it('rejects an empty name', function () {
    expect(fn () => app(CreateDirectory::class)->handle($this->user, '   '))
        ->toThrow(InvalidArgumentException::class);
});
