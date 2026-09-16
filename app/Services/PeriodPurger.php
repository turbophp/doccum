<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PeriodNotPurgeable;
use App\Models\ArchivePeriod;
use App\Models\File;
use App\Support\PurgePlan;
use App\Support\PurgeReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reclaims a period's storage: rows and objects, permanently.
 *
 * This is the only irreversible operation in doccum, so it is built around
 * saying no. Three guards must all pass -- the period is archived, it has
 * aged past the retention window, and nothing in it is under legal hold --
 * and a refusal names every reason rather than only the first, because an
 * operator who fixes one blocker and is refused again learns nothing.
 *
 * `plan()` answers "what would this destroy" without writing, so the
 * question can be asked safely; `purge()` is `plan()` plus the deletions.
 * See spec §9.
 */
class PeriodPurger
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly SearchIndexer $indexer,
    ) {}

    public function plan(int $year, ?int $month = null): PurgePlan
    {
        $query = $this->filesQuery($year, $month);

        $fileCount = (clone $query)->count();
        $byteCount = (int) (clone $query)->sum('size');
        $blockers = $this->blockers($year, $month);

        return new PurgePlan(
            year: $year,
            month: $month,
            purgeable: $blockers === [],
            fileCount: $fileCount,
            byteCount: $byteCount,
            blockers: $blockers,
        );
    }

    public function purge(int $year, ?int $month = null): PurgeReport
    {
        $plan = $this->plan($year, $month);

        if (! $plan->purgeable) {
            throw PeriodNotPurgeable::because($year, $month, $plan->blockers);
        }

        $fileCount = 0;
        $byteCount = 0;
        $versionCount = 0;
        $objectCount = 0;

        DB::transaction(function () use ($year, $month, &$fileCount, &$byteCount, &$versionCount, &$objectCount): void {
            $this->filesQuery($year, $month)
                ->with(['versions', 'properties'])
                ->chunkById(200, function ($files) use (&$fileCount, &$byteCount, &$versionCount, &$objectCount): void {
                    foreach ($files as $file) {
                        $keys = $file->versions
                            ->pluck('object_key')
                            ->filter(fn (?string $key): bool => $key !== null && $key !== '')
                            ->values()
                            ->all();

                        // Objects before rows, on purpose. If this throws, the
                        // transaction rolls back and the rows survive, so a
                        // re-run finds them and finishes the job. The other
                        // order would leave objects nothing points at, which
                        // shows up only as a storage bill.
                        if ($keys !== []) {
                            $this->storage->delete(...$keys);
                        }

                        // A property's projection has to be forgotten while
                        // the property still exists: File::forceDeleted mass
                        // deletes them, and a mass delete fires no model
                        // events, so nothing else would ever remove them from
                        // the index.
                        foreach ($file->properties as $property) {
                            $this->indexer->forget($property);
                        }

                        $this->indexer->forget($file);

                        $versionCount += $file->versions->count();
                        $objectCount += count($keys);
                        $byteCount += (int) $file->size;
                        $fileCount++;

                        // Cascades to file_versions and, through them, to
                        // file_texts; the forceDeleted hook takes the
                        // properties.
                        $file->forceDelete();
                    }
                });

            ArchivePeriod::query()
                ->where('year', $year)
                ->where('month', $month)
                ->update(['purged_at' => now(), 'updated_at' => now()]);
        });

        return new PurgeReport(
            year: $year,
            month: $month,
            fileCount: $fileCount,
            byteCount: $byteCount,
            versionCount: $versionCount,
            objectCount: $objectCount,
        );
    }

    /**
     * Every period that is archived, not yet purged, and past its retention
     * window -- what scheduled purging would consider.
     *
     * @return Collection<int, ArchivePeriod>
     */
    public function expired(): Collection
    {
        $cutoff = $this->retentionCutoff();

        if ($cutoff === null) {
            return new Collection;
        }

        return ArchivePeriod::query()
            ->whereNotNull('archived_at')
            ->whereNull('purged_at')
            ->where('archived_at', '<=', $cutoff)
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    /** @return array<int, string> */
    private function blockers(int $year, ?int $month): array
    {
        $blockers = [];

        $period = ArchivePeriod::query()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($period === null || ! $period->isArchived()) {
            $blockers[] = 'It has not been archived.';
        }

        $years = config('doccum.retention.purge_after_years');
        $cutoff = $this->retentionCutoff();

        if ($years === null || $cutoff === null) {
            // Unset means nobody has decided how long documents are kept.
            // Reading that as "keep nothing" would make an instance that was
            // never configured the one that deletes.
            $blockers[] = 'No retention window is configured.';
        } elseif ($period?->archived_at !== null && $period->archived_at->greaterThan($cutoff)) {
            // Measured from archived_at rather than from the end of the
            // period: that is when the operator declared it closed, and it is
            // always the later of the two, so it is the safer clock.
            $blockers[] = sprintf(
                'It is inside the %d-year retention window until %s.',
                (int) $years,
                $period->archived_at->addYears((int) $years)->toDateString(),
            );
        }

        $held = (clone $this->filesQuery($year, $month))->where('legal_hold', true)->count();

        if ($held > 0) {
            // The whole period stays, not merely the held files: a partial
            // purge of a period under legal hold is exactly what a hold
            // exists to prevent.
            $blockers[] = sprintf('%d file(s) are under legal hold.', $held);
        }

        return $blockers;
    }

    private function retentionCutoff(): ?CarbonImmutable
    {
        $years = config('doccum.retention.purge_after_years');

        return $years === null ? null : now()->subYears((int) $years);
    }

    /** @return Builder<File> */
    private function filesQuery(int $year, ?int $month): Builder
    {
        // withTrashed, always: trash keeps its objects, so a trashed file is
        // exactly the storage a purge exists to reclaim.
        $query = File::withTrashed()->where('period_year', $year);

        if ($month !== null) {
            $query->where('period_month', $month);
        }

        return $query;
    }
}
