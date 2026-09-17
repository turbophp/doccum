<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * The dense file table and the period spine (design plan §4 and §7).
 *
 * This component owns exactly one directory's files. Subdirectory navigation
 * is the tree pane's job (a separate task); folding folder rows in here would
 * mean two components deciding what the viewer may reach, which is exactly
 * what the DirectoryAccess seam exists to prevent (CLAUDE.md, "Seams -- do
 * not bypass"). Access is decided once, in mount(), through FilePolicy's
 * companion DirectoryPolicy -- nothing below that point re-decides
 * visibility in the view; the query is filtered by the directory the viewer
 * was already granted.
 *
 * Selection lives entirely client-side in resources/js/shell/selection.js.
 * Design plan §5 motion #1 ("Select") is explicitly "no animation": a
 * Livewire round trip before a row's background changes would itself read as
 * lag. This component only renders the id a row carries (`data-row-id`) for
 * that store to key against; it never tracks which rows are selected.
 */
class FileList extends Component
{
    /** @var array<int, string> */
    private const SORTS = ['period', 'name', 'modified', 'size'];

    public Directory $directory;

    /**
     * The default: newest period first, then newest within it. Only this
     * sort groups rows under period bands and draws the spine's period
     * lines (§7) -- switching to any other sort is what the design means by
     * "sorting by name or size", and it must drop grouping while every hold
     * tick stays put.
     *
     * @var 'period'|'name'|'modified'|'size'
     */
    public string $sort = 'period';

    /** @var 'asc'|'desc' */
    public string $direction = 'desc';

    /**
     * Archived periods, fetched once per request regardless of how many
     * distinct periods this listing spans.
     *
     * @var Collection<int, ArchivePeriod>|null
     */
    private ?Collection $archivedPeriods = null;

    public function mount(Directory $directory): void
    {
        $this->authorize('view', $directory);

        $this->directory = $directory;
    }

    /**
     * Clicking a column header sorts by it; clicking the same one again
     * flips direction.
     */
    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTS, true)) {
            return;
        }

        if ($this->sort === $field) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $field;
        $this->direction = $field === 'name' ? 'asc' : 'desc';
    }

    public function render(): View
    {
        $grouped = $this->sort === 'period';
        $files = $this->filesQuery()->get();

        return view('livewire.files.file-list', [
            'rows' => $this->rowsFor($files, $grouped),
            'grouped' => $grouped,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ]);
    }

    /**
     * Filtered by the directory the viewer was already granted in mount() --
     * the DirectoryAccess seam, never re-decided here or in the view. Eager
     * loads exactly what a row needs (the current version and its extracted
     * text) and joins the creator's name, so the whole table renders from
     * one query rather than one query per row.
     *
     * @return Builder<File>
     */
    private function filesQuery(): Builder
    {
        $query = File::query()
            ->where('files.directory_id', $this->directory->getKey())
            ->leftJoin('users', 'users.id', '=', 'files.created_by')
            ->select('files.*')
            ->addSelect('users.name as creator_name')
            ->with(['currentVersion.text']);

        return match ($this->sort) {
            'name' => $query->orderBy('files.name', $this->direction),
            'size' => $query->orderBy('files.size', $this->direction),
            'modified' => $query->orderBy('files.updated_at', $this->direction),
            default => $query
                ->orderBy('files.period_year', $this->direction)
                ->orderBy('files.period_month', $this->direction)
                ->orderBy('files.updated_at', 'desc'),
        };
    }

    /**
     * Builds the flat list the view iterates: a period-band entry followed
     * by its files, repeated per period, when grouped -- or one file entry
     * per row, in query order, when not. Every file entry carries its own
     * `legal_hold` flag regardless of `$grouped`, which is what lets the
     * view keep drawing the spine's hold tick when bands and period lines
     * are gone (design plan §7).
     *
     * @param  Collection<int, File>  $files
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(Collection $files, bool $grouped): array
    {
        if (! $grouped) {
            return $files->map(fn (File $file): array => $this->fileRow($file))->all();
        }

        $rows = [];
        $currentKey = null;

        foreach ($files as $file) {
            $key = $file->period_year.'-'.$file->period_month;

            if ($key !== $currentKey) {
                $rows[] = $this->bandRow((int) $file->period_year, (int) $file->period_month);
                $currentKey = $key;
            }

            $rows[] = $this->fileRow($file);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function bandRow(int $year, int $month): array
    {
        $period = $this->archivePeriodFor($year, $month);

        return [
            'type' => 'band',
            'year' => $year,
            'month' => $month,
            'label' => Carbon::createFromDate($year, $month, 1)->format('F Y'),
            'archived' => $period !== null,
            'archived_at' => $period?->archived_at?->format('j M Y'),
            'file_count' => $period?->file_count,
            'byte_count' => $period !== null ? $this->formatBytes($period->byte_count) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function fileRow(File $file): array
    {
        $archived = $this->archivePeriodFor((int) $file->period_year, (int) $file->period_month) !== null;
        // A version with no `file_texts` row yet (extraction not dispatched
        // or not yet run) reads the same as ExtractionStatus::Pending's
        // value: a missing row is never ambiguous with "will never finish".
        $status = $file->currentVersion?->text?->status->value ?? 'pending';
        $version = $file->currentVersion?->version_number;

        $modified = $file->updated_at;

        return [
            'type' => 'file',
            'id' => $file->getKey(),
            'name' => $file->name,
            'type_icon' => $this->typeIcon($file->mime),
            'legal_hold' => $file->legal_hold,
            'archived' => $archived,
            'extraction' => $status,
            // Fixed order for the full (>=1024px) state cell (design plan
            // §4: "hold, extraction, archived lock"). Up to three; none at
            // all for a healthy, open, extracted file -- a tick on every row
            // would be noise.
            'state_icons' => $this->stateIcons($file->legal_hold, $status, $archived),
            // A different priority for the single glyph the state cell
            // narrows to below 1024px (design plan §4: "highest priority
            // wins: hold > failed > archived > pending") -- note archived
            // outranks a merely pending extraction there, unlike the fixed
            // order above.
            'state_icon_narrow' => $this->narrowStateIcon($file->legal_hold, $status, $archived),
            'period_label' => sprintf('%04d-%02d', $file->period_year, $file->period_month),
            'owner' => $file->getAttribute('creator_name'),
            'modified_full' => $modified?->format('Y-m-d H:i'),
            'modified_medium' => $modified?->format('m-d H:i'),
            'modified_short' => $modified?->format('m-d'),
            'size' => $this->formatBytes($file->size),
            'version' => $version !== null ? 'v'.$version : null,
        ];
    }

    /**
     * @return list<array{icon: string, color: string, label: string}>
     */
    private function stateIcons(bool $held, string $extraction, bool $archived): array
    {
        $icons = [];

        if ($held) {
            $icons[] = ['icon' => 'lock-closed', 'color' => 'hold', 'label' => __('Under legal hold')];
        }

        if ($extraction === 'failed') {
            $icons[] = ['icon' => 'exclamation-triangle', 'color' => 'attention', 'label' => __('Extraction failed')];
        } elseif (in_array($extraction, ['pending', 'processing'], true)) {
            $icons[] = ['icon' => 'clock', 'color' => 'ink-2', 'label' => __('Extraction pending')];
        } elseif ($extraction === 'unsupported') {
            $icons[] = ['icon' => 'document-minus', 'color' => 'ink-2', 'label' => __('No extracted text')];
        }

        if ($archived) {
            $icons[] = ['icon' => 'archive-box', 'color' => 'ink-2', 'label' => __('Archived period')];
        }

        return $icons;
    }

    /** @return array{icon: string, color: string, label: string}|null */
    private function narrowStateIcon(bool $held, string $extraction, bool $archived): ?array
    {
        return match (true) {
            $held => ['icon' => 'lock-closed', 'color' => 'hold', 'label' => __('Under legal hold')],
            $extraction === 'failed' => ['icon' => 'exclamation-triangle', 'color' => 'attention', 'label' => __('Extraction failed')],
            $archived => ['icon' => 'archive-box', 'color' => 'ink-2', 'label' => __('Archived period')],
            in_array($extraction, ['pending', 'processing'], true) => ['icon' => 'clock', 'color' => 'ink-2', 'label' => __('Extraction pending')],
            $extraction === 'unsupported' => ['icon' => 'document-minus', 'color' => 'ink-2', 'label' => __('No extracted text')],
            default => null,
        };
    }

    /**
     * The archived ArchivePeriod row covering (year, month), if any -- a
     * whole-year archive (month null) covers every month within it. See
     * ArchivePeriod::isArchivedFor(), which this mirrors but keeps the row
     * itself, since the band and the state glyph both need the recorded
     * counts and the archived date, not just a boolean.
     */
    private function archivePeriodFor(int $year, int $month): ?ArchivePeriod
    {
        return $this->archivedPeriods()->first(
            fn (ArchivePeriod $period): bool => $period->year === $year && ($period->month === null || $period->month === $month),
        );
    }

    /** @return Collection<int, ArchivePeriod> */
    private function archivedPeriods(): Collection
    {
        return $this->archivedPeriods ??= ArchivePeriod::query()->whereNotNull('archived_at')->get();
    }

    /**
     * Right-aligned, two-or-three significant digits, unit after a thin
     * space: "48.2 MB", "912 KB", "3.0 GB" (design plan §2, "Numerals").
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        $decimals = $unit === 0 ? 0 : ($value < 100 ? 1 : 0);

        return number_format($value, $decimals)."\u{2009}".$units[$unit];
    }

    /** A single-weight monochrome glyph per broad file type. No colour: file-type glyphs never carry one (design plan §1). */
    private function typeIcon(?string $mime): string
    {
        if ($mime === null) {
            return 'document';
        }

        return match (true) {
            str_starts_with($mime, 'image/') => 'photo',
            in_array($mime, ['application/vnd.ms-excel', 'text/csv'], true) => 'table-cells',
            str_contains($mime, 'spreadsheetml') => 'table-cells',
            in_array($mime, ['application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z-compressed'], true) => 'archive-box',
            $mime === 'application/pdf' => 'document-text',
            str_starts_with($mime, 'text/') => 'document-text',
            str_contains($mime, 'wordprocessingml') => 'document-text',
            default => 'document',
        };
    }
}
