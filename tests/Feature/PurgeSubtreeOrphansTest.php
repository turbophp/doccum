<?php

declare(strict_types=1);

use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\Property;
use App\Models\PropertyDefinition;
use Illuminate\Support\Facades\Storage;

/**
 * Reproduces issue #86: files.directory_id carries an ON DELETE CASCADE
 * foreign key, so force-deleting a Directory removes every descendant File
 * ROW in SQL without PHP ever running per file -- the cascade never reaches
 * App\Services\DocumentStorage, so every object those files owned is left
 * in the store with nothing referencing it.
 *
 * Every assertion here that matters is against the fake object store, not
 * the rows: the cascade this action exists to route around removes the rows
 * on its own, on the broken code exactly as on the fixed code, so a row-count
 * assertion would pass either way and prove nothing.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->root = Directory::factory()->create(['name' => 'Root']);
    $this->child = Directory::factory()->for($this->root, 'parent')->create(['name' => 'Child']);

    $this->rootFile = File::factory()->for($this->root, 'directory')->create(['name' => 'root.pdf']);
    $this->childFile = File::factory()->for($this->child, 'directory')->create(['name' => 'child.pdf']);

    // One file soft-deleted independently, in the root, before the purge.
    $this->trashedFile = File::factory()->for($this->root, 'directory')->create(['name' => 'trashed.pdf']);
    $this->trashedFile->delete();

    // One child directory soft-deleted independently, with a live file
    // still inside it -- the case Directory::descendants() would miss,
    // because it runs through static::query(), which the SoftDeletes
    // global scope filters.
    $this->trashedChild = Directory::factory()->for($this->root, 'parent')->create(['name' => 'TrashedChild']);
    $this->trashedChildFile = File::factory()->for($this->trashedChild, 'directory')->create(['name' => 'in-trashed-child.pdf']);
    $this->trashedChild->delete();

    $this->objectKeys = [];

    foreach ([
        'root' => $this->rootFile,
        'child' => $this->childFile,
        'trashed' => $this->trashedFile,
        'trashedChild' => $this->trashedChildFile,
    ] as $label => $file) {
        $version = FileVersion::factory()->for($file)->create([
            'version_number' => 1,
            'object_key' => "files/2026/01/{$file->uuid}/v1/{$label}.pdf",
        ]);
        Storage::disk('documents')->put($version->object_key, "contents-{$label}");
        $this->objectKeys[$label] = $version->object_key;
    }

    $definition = PropertyDefinition::factory()->create();

    $this->properties = [
        'child' => Property::factory()->create([
            'property_definition_id' => $definition->id,
            'subject_type' => 'directory',
            'subject_id' => $this->child->getKey(),
        ]),
        'trashedChildDir' => Property::factory()->create([
            'property_definition_id' => $definition->id,
            'subject_type' => 'directory',
            'subject_id' => $this->trashedChild->getKey(),
        ]),
        'rootFile' => Property::factory()->create([
            'property_definition_id' => $definition->id,
            'subject_type' => 'file',
            'subject_id' => $this->rootFile->getKey(),
        ]),
        'childFile' => Property::factory()->create([
            'property_definition_id' => $definition->id,
            'subject_type' => 'file',
            'subject_id' => $this->childFile->getKey(),
        ]),
        'trashedFile' => Property::factory()->create([
            'property_definition_id' => $definition->id,
            'subject_type' => 'file',
            'subject_id' => $this->trashedFile->getKey(),
        ]),
        'trashedChildFile' => Property::factory()->create([
            'property_definition_id' => $definition->id,
            'subject_type' => 'file',
            'subject_id' => $this->trashedChildFile->getKey(),
        ]),
    ];
});

it('removes every object beneath a force-deleted directory through DocumentStorage', function () {
    $this->root->forceDelete();

    // The primary evidence: the objects are gone from the store itself.
    // Asserting the rows are gone instead would pass on the broken cascade
    // too, since the cascade is precisely what removes the rows.
    // Collected rather than asserted one at a time, so a failure names every
    // object that survived instead of stopping at the first. The four are
    // four different bugs -- the root's file, the one a level down, the
    // independently trashed file, and the one inside the independently
    // trashed child -- and a per-key toBeFalse() would report them
    // identically and hide the other three.
    $survivors = [];

    foreach ($this->objectKeys as $label => $key) {
        if (Storage::disk('documents')->exists($key)) {
            $survivors[] = $label;
        }
    }

    expect($survivors)->toBe([]);
});

it('deletes the properties of every descendant directory and file', function () {
    $this->root->forceDelete();

    foreach ($this->properties as $property) {
        expect(Property::find($property->getKey()))->toBeNull();
    }
});

it('leaves a sibling directory and its object untouched', function () {
    $other = Directory::factory()->create(['name' => 'Other']);
    $otherFile = File::factory()->for($other, 'directory')->create(['name' => 'other.pdf']);
    $otherVersion = FileVersion::factory()->for($otherFile)->create([
        'version_number' => 1,
        'object_key' => "files/2026/01/{$otherFile->uuid}/v1/other.pdf",
    ]);
    Storage::disk('documents')->put($otherVersion->object_key, 'contents-other');

    $this->root->forceDelete();

    expect(Storage::disk('documents')->exists($otherVersion->object_key))->toBeTrue()
        ->and(File::withTrashed()->find($otherFile->id))->not->toBeNull();
});
