<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Services\DirectoryAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The shell's 24px footer strip (design plan §3, §6): the current folder's
 * counts, the selection count once anything is selected, and the sentence a
 * blocked drag or menu action explains itself with.
 *
 * This component is rendered once by `layouts::shell`, outside the pane that
 * owns navigation (`App\Livewire\Shell\Files`), so it never receives the
 * current directory as a prop. Instead it mirrors the same `dir` query
 * parameter Files.php binds (for a page load or reload) and listens for the
 * same `directory-selected` event the tree and the list already dispatch
 * (for everything after) -- the single mechanism the rest of the shell uses
 * to say "this is where you are", never re-decided here.
 *
 * Selection itself stays exactly where design plan §5 motion #1 puts it:
 * entirely client-side, in the `selection` Alpine store
 * (resources/js/shell/selection.js), so a row's background never waits on a
 * Livewire round trip. This component reads that same global store from its
 * own view to render "N selected" and to decide when to hide the folder
 * counts in its place -- it never learns which rows are selected, only
 * whether anything is, and it never calls into Livewire to find out.
 */
class StatusBar extends Component
{
    /**
     * Mirrors Files.php's own `dir` binding so a page load or reload starts
     * this component pointed at the same folder, without Files.php having to
     * hand it down.
     */
    #[Url(as: 'dir', except: '')]
    public string $directoryId = '';

    /**
     * The sentence a blocked drag or a disabled context-menu item explains
     * itself with (design plan §6): a plain string, not a code or an enum --
     * this component agrees with nothing in it and only displays it.
     *
     * Other components dispatch it, e.g.:
     *
     *     $this->dispatch('action-blocked', reason: __('Under legal hold'));
     *
     * It is shown for exactly the render that follows -- dehydrate() below
     * clears it immediately afterwards, so the next interaction (any other
     * request this component takes part in, whichever component triggers
     * it) starts clean instead of pinning a stale refusal on screen.
     */
    public ?string $blockedReason = null;

    /**
     * Both the tree and the file list dispatch this rather than navigating
     * themselves (see Files.php); this component listens for the same event
     * so its idea of "the current folder" can never drift from theirs.
     */
    #[On('directory-selected')]
    public function onDirectorySelected(int $directoryId): void
    {
        $this->directoryId = (string) $directoryId;
    }

    #[On('action-blocked')]
    public function explainBlock(string $reason): void
    {
        $this->blockedReason = $reason;
    }

    /**
     * Shown for exactly one render (see the property's own docblock).
     */
    public function dehydrate(): void
    {
        $this->blockedReason = null;
    }

    public function render(): View
    {
        $directory = $this->directoryId !== ''
            ? Directory::find((int) $this->directoryId)
            : null;

        return view('livewire.shell.status-bar', [
            'counts' => $directory !== null ? $this->countsFor($directory) : null,
        ]);
    }

    /**
     * One aggregate query for the folder's items and bytes, one bounded
     * query for how many of them are under legal hold, and one bounded query
     * for how many sit in an archived period -- never a query per file
     * (CLAUDE.md and design plan §4 both call this out: a status bar that
     * costs a query per row is how a dense list gets slow).
     *
     * Every one of those queries is filtered through DirectoryAccess's own
     * `viewableDirectoryIds()`, not just trusted because this component was
     * told about the directory: a folder's totals must never include, or
     * even confirm the existence of, files the viewer cannot see. A folder
     * this viewer cannot reach at all resolves to the same all-zero counts
     * as a folder that is genuinely empty -- which is the point: the two
     * must be indistinguishable from here, or the arithmetic itself would be
     * the leak.
     *
     * @return array{items: int, held: int, archived: int, size: string}
     */
    private function countsFor(Directory $directory): array
    {
        $user = auth()->user();

        if ($user === null) {
            return ['items' => 0, 'held' => 0, 'archived' => 0, 'size' => $this->formatBytes(0)];
        }

        $viewableIds = app(DirectoryAccess::class)->viewableDirectoryIds($user);

        // toBase() drops back to the plain query builder before fetching, so
        // the result is a stdClass carrying exactly the aliases selected
        // above -- not a File model, which has no `items`, `bytes` or `held`
        // column and would only make the aggregate look like a row that
        // does not exist.
        //
        // `sum(case when legal_hold then 1 else 0 end)`, not `sum(legal_hold)`
        // directly: `legal_hold` is declared `boolean` (see the files table
        // migration), which SQLite and MySQL are happy to sum as 0/1, but
        // PostgreSQL will not -- its boolean type has no implicit numeric
        // cast, so `sum()` on the bare column errors there. The CASE
        // expression is the one spelling that runs unchanged on all three
        // (see this file's entry in SCHEMA_AUDIT_DRIVER_DEPENDENT_SITES).
        $base = File::query()
            ->where('directory_id', $directory->getKey())
            ->whereIn('directory_id', $viewableIds)
            ->selectRaw('count(*) as items, coalesce(sum(size), 0) as bytes, coalesce(sum(case when legal_hold then 1 else 0 end), 0) as held')
            ->toBase()
            ->first();

        $archivedPeriods = ArchivePeriod::query()->whereNotNull('archived_at')->get(['year', 'month']);

        $archived = $archivedPeriods->isEmpty() ? 0 : File::query()
            ->where('directory_id', $directory->getKey())
            ->whereIn('directory_id', $viewableIds)
            ->where(function ($query) use ($archivedPeriods): void {
                foreach ($archivedPeriods as $period) {
                    $query->orWhere(function ($query) use ($period): void {
                        $query->where('period_year', $period->year);

                        if ($period->month !== null) {
                            $query->where('period_month', $period->month);
                        }
                    });
                }
            })
            ->count();

        return [
            'items' => (int) $base->items,
            'held' => (int) $base->held,
            'archived' => $archived,
            'size' => $this->formatBytes((int) $base->bytes),
        ];
    }

    /**
     * Right-aligned, two-or-three significant digits, unit after a thin
     * space: "48.2 MB", "912 KB", "3.0 GB" (design plan §2, "Numerals").
     *
     * Duplicated from `App\Livewire\Files\FileList`'s private helper of the
     * same shape rather than shared, because that file belongs to a
     * different task in this branch (see this component's own ownership
     * note); the two are free to diverge if the byte-formatting rule ever
     * needs to.
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
}
