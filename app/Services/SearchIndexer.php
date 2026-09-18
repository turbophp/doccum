<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Models\SearchDocument;
use App\Search\SearchIndex;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Builds the flattened `search_documents` projection for one subject.
 *
 * A directory, file or property cannot be searched in isolation: extracted
 * text lives on `file_texts`, property values on `properties`, and a
 * document's position in the tree is what decides who may see it. This is
 * the one place that flattens all of that into a single row per subject. See
 * spec §8.
 */
class SearchIndexer
{
    public function __construct(private readonly SearchIndex $index) {}

    public function index(Model $subject): SearchDocument
    {
        $attributes = match (true) {
            $subject instanceof Directory => $this->forDirectory($subject),
            $subject instanceof File => $this->forFile($subject),
            $subject instanceof Property => $this->forProperty($subject),
            default => throw new InvalidArgumentException(
                'Cannot build a search projection for '.$subject::class.'.'
            ),
        };

        $document = SearchDocument::updateOrCreate(
            ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()],
            $attributes + ['indexed_at' => now()],
        );

        // Writing the projection row is only half the job: until it reaches the
        // index it is not searchable at all. Keeping these together means no
        // caller can build a projection and forget to publish it.
        $this->index->put($document);

        return $document;
    }

    public function forget(Model $subject): void
    {
        $documents = SearchDocument::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->get();

        foreach ($documents as $document) {
            // Remove from the index first: a row left in the index with no
            // projection behind it would still match and then vanish from the
            // results, which looks like corruption.
            $this->index->forget($document);
            $document->delete();
        }
    }

    /**
     * A directory is positioned at itself, so that force-deleting it cascades
     * onto its own projection row through `directory_id`'s foreign key, the
     * same safety net a file or property gets from its owning directory.
     *
     * @return array<string, mixed>
     */
    private function forDirectory(Directory $directory): array
    {
        return [
            'title' => $directory->name,
            'body' => $this->flattenProperties($directory),
            'directory_id' => $directory->getKey(),
            'ancestor_ids' => $directory->ancestorIds(),
            'period_year' => null,
            'period_month' => null,
            'mime' => null,
            'extension' => null,
            'owner_id' => $directory->created_by,
        ];
    }

    /** @return array<string, mixed> */
    private function forFile(File $file): array
    {
        $body = trim(($file->extractedText() ?? '')."\n".$this->flattenProperties($file));

        return [
            'title' => $file->name,
            'body' => $body !== '' ? $body : null,
            'directory_id' => $file->directory_id,
            'ancestor_ids' => $file->directory?->ancestorIds() ?? [],
            'period_year' => $file->period_year,
            'period_month' => $file->period_month,
            'mime' => $file->mime,
            'extension' => $this->extensionFor($file->name),
            'owner_id' => $file->created_by,
        ];
    }

    /**
     * Positioned at its subject's directory, not its own, so it filters by
     * access identically to the file or directory it belongs to.
     *
     * @return array<string, mixed>
     */
    private function forProperty(Property $property): array
    {
        $directory = $this->directoryFor($property->subject);

        return [
            'title' => $property->definition->label,
            'body' => trim($property->definition->label.' '.$this->stringifyValue($property)),
            'directory_id' => $directory?->getKey(),
            'ancestor_ids' => $directory?->ancestorIds() ?? [],
            'period_year' => null,
            'period_month' => null,
            'mime' => null,
            'extension' => null,
            'owner_id' => null,
        ];
    }

    private function directoryFor(?Model $subject): ?Directory
    {
        return match (true) {
            $subject instanceof Directory => $subject,
            $subject instanceof File => $subject->directory,
            default => null,
        };
    }

    /** Every property on the subject, as "Label value" lines. */
    private function flattenProperties(Directory|File $subject): ?string
    {
        $lines = $subject->properties()
            ->with('definition')
            ->get()
            ->map(fn (Property $property): string => trim(
                $property->definition->label.' '.$this->stringifyValue($property)
            ))
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");

        return $lines !== '' ? $lines : null;
    }

    private function stringifyValue(Property $property): string
    {
        $value = $property->value;

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    private function extensionFor(string $name): ?string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : null;
    }
}
