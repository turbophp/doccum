<section class="w-full">
    {{-- Archive progress. Polls only while the row is non-terminal, which is
         what ArchiveStatus::isTerminal() exists to answer -- a poll with no
         stopping condition runs for the life of the page.

         The download is started from the browser once the row turns Ready,
         rather than by redirecting the Livewire response: a redirect would
         navigate away from the listing, and the person asked for a zip, not
         for their place in the directory to be lost. --}}
    {{-- Use the block form here, never the single-expression one. Blade lifts
         raw PHP blocks out of the template before it strips comments, pairing
         each opening directive with the next closing one, so a
         single-expression opener in the same file pairs with a LATER closing
         directive and swallows everything in between. That is how the detail
         panel's own block was left unopened and $subject undefined.

         And for the same reason this note spells none of those directives
         out: written literally, the words in a comment are themselves picked
         up as a block, which swallowed the pane container below it. --}}
    @php
        $archive = $this->archiveProgress();
    @endphp

    @if ($archive)
        <div
            class="flex items-center gap-3 border-b border-rule bg-chrome px-4 py-2 text-sm"
            data-test="archive-progress"
            @if (! $archive->status->isTerminal()) wire:poll.1s @endif
        >
            @if ($archive->status === \App\Enums\ArchiveStatus::Failed)
                <flux:icon.exclamation-triangle variant="micro" class="shrink-0 text-attention" />
                <span class="text-ink">{{ __('The archive could not be built.') }}</span>
            @elseif ($archive->status === \App\Enums\ArchiveStatus::Ready)
                <flux:icon.check-circle variant="micro" class="shrink-0 text-ink-2" />
                <span class="text-ink">{{ __('Download started.') }}</span>

                <span
                    x-data
                    x-init="window.location = @js(route('directories.archives.download', $archive)); $wire.dismissArchive()"
                ></span>
            @else
                <flux:icon.arrow-path variant="micro" class="shrink-0 animate-spin text-ink-2" />
                <span class="text-ink">{{ __('Zipping :done of :total…', ['done' => $archive->completed_files, 'total' => $archive->total_files]) }}</span>

                <span class="h-1 w-40 overflow-hidden rounded bg-rule">
                    <span class="block h-full bg-select" style="width: {{ $archive->percentComplete() }}%"></span>
                </span>
            @endif

            <flux:link wire:click="dismissArchive" variant="subtle" class="ml-auto cursor-pointer text-xs text-ink-2 hover:text-ink">{{ __('Dismiss') }}</flux:link>
        </div>
    @endif

    <div class="flex h-[calc(100vh-3.5rem)] items-stretch">
        {{-- The Files sidebar (spec §10): Home pinned first, then the
             viewer's reach roots and their viewable descendants --
             $sidebarTree, resolved ENTIRELY by DirectoryAccess::reachTree()
             (render()'s own comment says why: filtering happens in the
             query, never here). Collapse state lives in localStorage,
             keyed per directory id, wrapped in try/catch because it throws
             in a private window -- see CLAUDE.md and the doccum design
             spec §10a. --}}
        <aside
            class="w-60 shrink-0 overflow-auto border-r border-rule bg-sheet px-2 py-3"
            data-test="directory-tree"
            x-data="{
                expanded: (() => {
                    try {
                        const raw = localStorage.getItem('doccum-sidebar-expanded');

                        return raw ? JSON.parse(raw) : {};
                    } catch (e) {
                        return {};
                    }
                })(),
                isExpanded(id) {
                    return this.expanded[id] !== false;
                },
                toggle(id) {
                    this.expanded[id] = ! this.isExpanded(id);

                    try {
                        localStorage.setItem('doccum-sidebar-expanded', JSON.stringify(this.expanded));
                    } catch (e) {
                        // Private window, or storage disabled -- the tree
                        // still renders (everything simply stays expanded
                        // for the rest of this page load).
                    }
                },
            }"
        >
            @if ($homeDirectory)
                {{-- item/home-dashboard (issue #16): the topbar ALSO has a
                     nav item named "Home" (spec §10 names both this pinned
                     entry and the topbar's one "Home", so the collision is
                     the spec's, not this component's). WCAG "label in name"
                     requires an accessible name that CONTAINS the visible
                     text, so this keeps the visible text "Home" and adds
                     aria-label="Home directory" -- distinguishing it from
                     the topbar's link without renaming what a viewer reads.
                     Not the username (the tree's first entry already reads
                     that) and not "My files" -- a term spec §10 never uses.

                     A plain <a>, not flux:link, on purpose: flux:link is
                     only KNOWN to forward arbitrary attributes when there is
                     a real href turning it into an <a> (see the Trash link
                     comment below), and even then this codebase does not
                     know it forwards aria-label specifically -- if Flux put
                     it on some wrapping element instead of the <a> itself,
                     the ANCHOR's own accessible name (computed from ITS
                     attributes and content) would be unaffected and this
                     fix would silently do nothing. Writing the anchor by
                     hand removes that doubt. --}}
                <div data-test="sidebar-home" class="mb-2 border-b border-rule pb-2">
                    <a
                        href="{{ route('files.browse', $homeDirectory) }}"
                        wire:navigate
                        aria-label="{{ __('Home directory') }}"
                        @class([
                            'flex h-7 items-center gap-1.5 rounded px-1.5 text-sm no-underline',
                            'bg-chrome font-medium text-ink' => $directory?->getKey() === $homeDirectory->getKey(),
                            'text-ink-2 hover:bg-chrome/60 hover:text-ink' => $directory?->getKey() !== $homeDirectory->getKey(),
                        ])
                    >
                        <flux:icon.home variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                        {{ __('Home') }}
                    </a>
                </div>
            @endif

            <ul data-test="sidebar-tree" class="space-y-0.5">
                @include('livewire.files.partials.directory-tree', ['nodes' => $sidebarTree])
            </ul>
        </aside>

        <div class="min-w-0 flex-1 overflow-auto px-4 py-3">
            {{-- The breadcrumb belongs to the listing it describes, so it sits
                 in this pane directly above the column header rather than in a
                 bar of its own over all three panes.

                 Every ancestor the viewer may VIEW, root first, current
                 directory last -- and no others: an ancestor with no grant
                 anywhere on it (a grant made directly on a NESTED directory,
                 per DirectoryAccess) is not in $breadcrumbs at all, so there is
                 nothing here for the view itself to filter. See
                 Browser::breadcrumbTrail(). The final crumb (the directory
                 being browsed) carries no href, matching the read-only "you are
                 here" convention the rest of the app already used. --}}
            <div class="mb-2 flex min-h-8 flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                <div class="flex items-center gap-1.5">
                    <flux:icon.home variant="micro" class="shrink-0 text-ink-2" aria-hidden="true" />

                    @if ($breadcrumbs->isNotEmpty())
                        <flux:breadcrumbs>
                            @foreach ($breadcrumbs as $index => $crumb)
                                @if ($index === $breadcrumbs->count() - 1)
                                    <flux:breadcrumbs.item>
                                        <span data-test="breadcrumb-item" class="font-semibold text-ink">{{ $crumb->name }}</span>
                                    </flux:breadcrumbs.item>
                                @else
                                    <flux:breadcrumbs.item :href="route('files.browse', $crumb)" wire:navigate>
                                        <span data-test="breadcrumb-item">{{ $crumb->name }}</span>
                                    </flux:breadcrumbs.item>
                                @endif
                            @endforeach
                        </flux:breadcrumbs>
                    @else
                        <span class="font-semibold text-ink">{{ __('Files') }}</span>
                    @endif
                </div>

                @if (! empty($selectedIds) || ! empty($selectedDirectoryIds))
                    {{-- Only ever shown once something is ticked, and the count is what
                         proves a click actually reached selectRow() -- a resting "0
                         selected" label would pass whether or not selection worked at
                         all (CLAUDE.md: prefer an assertion that requires the feature
                         to DO something). --}}
                    <div class="flex items-center gap-2" data-test="bulk-actions">
                        <flux:text class="text-xs font-medium text-ink">{{ trans_choice(':count item selected|:count items selected', count($selectedIds) + count($selectedDirectoryIds), ['count' => count($selectedIds) + count($selectedDirectoryIds)]) }}</flux:text>
                        {{-- No `danger` variant: red in doccum means legal hold and
                             nothing else (design plan §1). Trashing is reversible --
                             Trash restores -- so it is an ordinary action, and the
                             wire:confirm below carries the weight instead. --}}
                        <flux:button
                            size="xs"
                            wire:click="bulkTrash"
                            wire:confirm="{{ __('Trash the selected files? They can be restored later from Trash.') }}"
                            data-test="bulk-trash-button"
                        >
                            {{ __('Trash selected') }}
                        </flux:button>
                    </div>
                @endif

                {{-- The directory's own actions, on the right of the row that
                     names it. They used to occupy a full-width bar of their own,
                     which gave three small controls the visual weight of a
                     section heading.

                     Plain inputs rather than flux:input, and aria-label rather
                     than a visible one: the accessible names are what the
                     container smoke drives (getByLabel('New folder'),
                     getByRole('button', { name: 'Upload' })), and they survive
                     losing the printed label while the layout does not survive
                     keeping it. The New folder field stays VISIBLE because the
                     smoke fills it, and fill() checks actionability; only the
                     file input is hidden, and it is hidden with sr-only rather
                     than display:none so it keeps a box Playwright can still
                     resolve. --}}
                {{-- The directory's own actions: two buttons that open a
                     dialog each, rather than three inline forms competing with
                     the breadcrumb for the same row. The forms themselves are
                     unchanged -- they move inside the dialogs, x-show rather
                     than x-if, so their inputs stay in the DOM and Livewire
                     keeps its bindings across opens. --}}
                <div class="ml-auto flex items-center gap-2" x-data="{ newFolderOpen: false, uploadOpen: false }">
                    <flux:button
                        size="xs"
                        icon="folder-plus"
                        x-on:click="newFolderOpen = true"
                        data-test="new-folder-button"
                    >{{ __('New folder') }}</flux:button>

                    @if ($directory)
                        {{-- aria-label, so this button's accessible name is
                             "Upload files" and the dialog's submit stays the only
                             control named exactly "Upload". Two buttons sharing an
                             exact accessible name is a strict-mode violation for
                             every locator that names it -- the same collision the
                             detail panel's duplicated file name caused. The visible
                             text is still "Upload", which the accessible name
                             contains, so WCAG label-in-name holds. --}}
                        <flux:button
                            size="xs"
                            variant="primary"
                            icon="arrow-up-tray"
                            x-on:click="uploadOpen = true"
                            aria-label="{{ __('Upload files') }}"
                            data-test="open-upload-button"
                        >{{ __('Upload') }}</flux:button>
                    @endif

                    {{-- item/trash-view (issue #15): the one path spec §10 names into
                         the Trash page ("Reached from Files"). Plain <div> wrapping a
                         real <a> (flux:link renders one when it has a real href),
                         not a data-test on flux:link itself -- Flux is only KNOWN to
                         forward arbitrary attributes on flux:button (CLAUDE.md). --}}
                    <div data-test="trash-link">
                        <flux:link :href="route('trash')" wire:navigate variant="subtle" class="inline-flex h-7 items-center gap-1.5 rounded border border-rule px-2 text-xs text-ink-2 hover:text-ink">
                            <flux:icon.trash variant="micro" />
                            {{ __('Trash') }}
                        </flux:link>
                    </div>

                    <x-modal state="newFolderOpen" :title="__('New folder')" test="new-folder-modal">
                        <form
                            wire:submit="createDirectory"
                            class="space-y-3"
                            x-on:folder-created.window="newFolderOpen = false"
                        >
                            <input
                                wire:model="newDirectoryName"
                                type="text"
                                aria-label="{{ __('Folder name') }}"
                                placeholder="{{ __('Name') }}"
                                class="h-9 w-full rounded border border-rule bg-sheet px-2 text-sm text-ink placeholder:text-ink-2 focus:border-select focus:outline-none focus:ring-1 focus:ring-select"
                            />

                            @error('newDirectoryName')
                                <p class="text-sm text-attention" data-test="new-folder-error">{{ $message }}</p>
                            @enderror

                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" type="button" x-on:click="newFolderOpen = false">{{ __('Cancel') }}</flux:button>
                                <flux:button size="sm" type="submit" variant="primary">{{ __('Create') }}</flux:button>
                            </div>
                        </form>
                    </x-modal>

                    @if ($directory)
                        {{-- wire:loading.attr="disabled" with wire:target naming the UPLOAD
                             PROPERTY -- not a method -- is what closes issue #106. A file input
                             posts its bytes to Livewire's upload endpoint the moment it changes,
                             and until that POST answers the server-side property is still
                             unpopulated. Clicking Upload inside that window dispatches store()
                             against an empty property: it fails `required|file`, and the
                             _finishUpload commit that lands a moment later re-renders over the
                             error, so the person sees no file stored and nothing said. PR #129's
                             probe caught it three times in one run, and the split is exactly the
                             upload endpoint's latency -- every discarded upload had it answer in
                             ~550ms, every stored one in ~18ms.

                             Targeting the property makes Livewire hold the button disabled for
                             the whole upload, so the racing click cannot be made. The container
                             smoke asserts the disabled state against a deliberately delayed
                             upload endpoint. --}}
                        <x-modal state="uploadOpen" :title="__('Upload a file')" test="upload-modal">
                            <form
                                wire:submit="store"
                                class="space-y-3"
                                data-test="upload-form"
                                x-on:file-uploaded.window="uploadOpen = false"
                            >
                                <input
                                    type="file"
                                    wire:model="upload"
                                    aria-label="{{ __('File') }}"
                                    class="block w-full text-sm text-ink file:mr-3 file:rounded file:border file:border-rule file:bg-chrome file:px-2 file:py-1 file:text-sm file:text-ink hover:file:bg-sheet"
                                />

                                @error('upload')
                                    <p class="text-sm text-attention" data-test="upload-error">{{ $message }}</p>
                                @enderror

                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" type="button" x-on:click="uploadOpen = false">{{ __('Cancel') }}</flux:button>
                                    <flux:button
                                        size="sm"
                                        type="submit"
                                        variant="primary"
                                        wire:loading.attr="disabled"
                                        wire:target="upload"
                                    >{{ __('Upload') }}</flux:button>
                                </div>
                            </form>
                        </x-modal>
                    @endif
                </div>
            </div>

            {{-- One table for both kinds of row, folders first: to the person
                 reading it they are the same thing, the contents of this
                 directory. Two stacked lists read as unrelated widgets and
                 their columns did not line up with each other. --}}
            <table data-test="files-table" class="w-full table-fixed text-left text-sm">
                <thead>
                    <tr class="border-b border-rule text-xs text-ink-2">
                        <th class="w-9 py-1.5"><span class="sr-only">{{ __('Select') }}</span></th>
                        <th class="w-6"></th>
                        {{-- Every header cell sorts by the SAME mechanism, sortBy(),
                             which resolves the column against Browser::SORTABLE --
                             never the raw string a click sends -- so there is nothing
                             here for the view itself to whitelist. --}}
                        <th class="py-1.5 font-medium"><flux:link wire:click="sortBy('name')" variant="subtle" class="cursor-pointer hover:text-ink">{{ __('Name') }}</flux:link></th>
                        <th class="w-44 py-1.5 font-medium"><flux:link wire:click="sortBy('owner')" variant="subtle" class="cursor-pointer hover:text-ink">{{ __('Owner') }}</flux:link></th>
                        <th class="w-44 py-1.5 font-medium"><flux:link wire:click="sortBy('modified')" variant="subtle" class="cursor-pointer hover:text-ink">{{ __('Modified') }}</flux:link></th>
                        <th class="w-24 py-1.5 text-right font-medium"><flux:link wire:click="sortBy('size')" variant="subtle" class="cursor-pointer hover:text-ink">{{ __('Size') }}</flux:link></th>
                        <th class="w-32 py-1.5"></th>
                    </tr>
                </thead>

                {{-- data-test scopes the container-smoke's own navigation: at the
                     root of the browser ($directory === null) this list and the
                     sidebar both render the SAME reach roots (issue #99), so a
                     directory's name is no longer a unique link on the landing
                     page and any locator has to say which of the two it means.
                     It scopes a <tbody> now rather than a <div>, which puts
                     folders in the same table as files without changing what the
                     attribute means or what it contains. --}}
                <tbody data-test="directories-list">
                    @foreach ($directories as $item)
                        <tr
                            draggable="true"
                            data-directory-id="{{ $item->id }}"
                            data-drop-directory-id="{{ $item->id }}"
                            @class([
                            'group h-8 border-b border-rule/40',
                            'hover:bg-chrome' => ! in_array($item->id, $selectedDirectoryIds, true),
                            'bg-select/10' => in_array($item->id, $selectedDirectoryIds, true),
                        ])>
                            {{-- Folders tick the same way files do. Plain
                                 toggling, no shift-range: see
                                 Browser::selectDirectoryRow(). --}}
                            <td class="px-2">
                                <input
                                    type="checkbox"
                                    data-test="directory-row-checkbox"
                                    class="accent-select"
                                    aria-label="{{ __('Select :name', ['name' => $item->name]) }}"
                                    @checked(in_array($item->id, $selectedDirectoryIds, true))
                                    x-on:click.stop="$wire.selectDirectoryRow({{ $item->id }})"
                                />
                            </td>
                            <td></td>
                            <td class="max-w-0 py-1">
                                <div class="flex min-w-0 items-center gap-2">
                                    <flux:icon.folder variant="micro" class="shrink-0 text-ink-2" />
                                    <flux:link :href="route('files.browse', $item)" wire:navigate variant="ghost" class="min-w-0 truncate font-medium text-ink">{{ $item->name }}</flux:link>
                                </div>
                            </td>
                            <td class="truncate py-1 text-ink-2">{{ $item->creator?->name }}</td>
                            <td class="num py-1 text-ink-2">{{ $item->updated_at?->format('Y-m-d H:i') }}</td>
                            <td class="py-1 text-right text-ink-2">&mdash;</td>
                            {{-- Icon actions, named by aria-label rather than by
                                 visible text. Opacity rather than hidden, so the row
                                 does not reflow on hover and the controls stay in the
                                 accessibility tree -- reachable by keyboard, and by
                                 Playwright, which treats opacity:0 as visible. --}}
                            <td class="py-1 text-right">
                                <span class="flex items-center justify-end gap-1 pr-1 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
                                    <flux:link
                                        :href="route('files.browse', $item)"
                                        wire:navigate
                                        variant="subtle"
                                        :aria-label="__('Open')"
                                        class="inline-flex size-6 items-center justify-center rounded text-ink-2 hover:bg-sheet hover:text-ink"
                                    ><flux:icon.folder-open variant="micro" /></flux:link>

                                    <flux:link
                                        wire:click="downloadDirectoryZip({{ $item->id }})"
                                        variant="subtle"
                                        data-test="directory-zip-button"
                                        :aria-label="__('Compress')"
                                        class="cursor-pointer inline-flex size-6 items-center justify-center rounded text-ink-2 hover:bg-sheet hover:text-ink"
                                    ><flux:icon.archive-box-arrow-down variant="micro" /></flux:link>

                                    <flux:link
                                        wire:click="selectDirectory({{ $item->id }})"
                                        variant="subtle"
                                        :aria-label="__('Details')"
                                        class="cursor-pointer inline-flex size-6 items-center justify-center rounded text-ink-2 hover:bg-sheet hover:text-ink"
                                    ><flux:icon.information-circle variant="micro" /></flux:link>

                                    @can('delete', $item)
                                        <flux:button
                                            variant="subtle"
                                            size="xs"
                                            icon="trash"
                                            :aria-label="__('Trash')"
                                            wire:click="trashDirectoryRow({{ $item->id }})"
                                            wire:confirm="{{ __('Trash this folder and everything beneath it? It can be restored later from Trash.') }}"
                                            data-test="row-trash-directory"
                                        />
                                    @endcan
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                <tbody>
                    @forelse ($files as $item)
                        {{-- The click handler lives on the ROW, in Alpine, not on a
                             wire:click -- Livewire's own click binding never sees the
                             raw DOM event, and shift/ctrl range-and-toggle selection
                             needs $event.shiftKey and $event.ctrlKey/$event.metaKey,
                             which only the browser's own event carries. Clicking the
                             name link below still ALSO fires this (the click bubbles
                             up from the <a> to this <tr>), so a plain click both
                             selects the row and opens the detail panel, which is the
                             "click-to-select behaviour that opens the detail panel"
                             this item was told to keep. --}}
                        {{-- Rows carry no transition, deliberately: selection is the
                             most repeated interaction in a file manager and motion #1
                             in the design plan is the absence of motion. A fade across
                             a shift-selected range is a visible smear. --}}
                        <tr
                            draggable="true"
                            data-test="file-row"
                            data-file-id="{{ $item->id }}"
                            x-on:click="$wire.selectRow({{ $item->id }}, $event.shiftKey, $event.ctrlKey || $event.metaKey)"
                            @class([
                                'group h-8 cursor-pointer border-b border-rule/40',
                                'hover:bg-chrome' => ! in_array($item->id, $selectedIds, true),
                                'bg-select/10' => in_array($item->id, $selectedIds, true),
                            ])
                        >
                            {{-- A plain <input type="checkbox">, not flux:checkbox: this
                                 carries a data-test the container smoke drives, and Flux is
                                 only KNOWN to forward arbitrary attributes on flux:button
                                 (data-test="trash-file-button" is driven that way by a
                                 mutation-proven check). An attribute landing on a wrapper
                                 instead of the real input is somewhere Playwright cannot
                                 reach.

                                 .stop because the row itself carries a click handler: without
                                 it one click on the box fires selectRow twice, once toggling
                                 and once undoing it. A box click toggles -- the ctrl gesture,
                                 which is what ticking a box means -- unless shift is held,
                                 which ranges. --}}
                            <td class="px-2">
                                <input
                                    type="checkbox"
                                    data-test="file-row-checkbox"
                                    class="accent-select"
                                    aria-label="{{ __('Select :name', ['name' => $item->name]) }}"
                                    @checked(in_array($item->id, $selectedIds, true))
                                    x-on:click.stop="$wire.selectRow({{ $item->id }}, $event.shiftKey, ! $event.shiftKey)"
                                />
                            </td>
                            {{-- State glyph, one column, quiet when there is nothing to
                                 say (design plan §4): a healthy file shows nothing at
                                 all, so a tick on every row cannot become noise. Hold
                                 is the only thing that takes `hold`. --}}
                            <td class="w-5">
                                @if ($item->legal_hold)
                                    <flux:icon.lock-closed
                                        variant="micro"
                                        class="text-hold"
                                        title="{{ __('Under legal hold') }}"
                                    />
                                @endif
                            </td>
                            <td class="max-w-0 py-1">
                                <div class="flex min-w-0 items-center gap-2">
                                    <flux:icon.document variant="micro" class="shrink-0 text-ink-2" />
                                    {{-- Clicking the name OPENS the file. preview() selects it
                                         too, so the detail panel behind the dialog describes what
                                         is being looked at; the row's own click still selects
                                         without opening, which is what shift and ctrl ranges
                                         need. --}}
                                    <flux:link wire:click="preview({{ $item->id }})" variant="ghost" class="min-w-0 cursor-pointer truncate text-ink">{{ $item->name }}</flux:link>
                                </div>
                            </td>
                            <td class="truncate py-1 text-ink-2">{{ $item->creator?->name }}</td>
                            <td class="num py-1 text-ink-2">{{ $item->updated_at?->format('Y-m-d H:i') }}</td>
                            <td class="num py-1 text-right text-ink-2">{{ \Illuminate\Support\Number::fileSize($item->size) }}</td>
                            <td class="py-1 text-right">
                                <span class="flex items-center justify-end gap-1 pr-1 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
                                    <flux:link
                                        wire:click="preview({{ $item->id }})"
                                        variant="subtle"
                                        :aria-label="__('View')"
                                        data-test="row-preview"
                                        class="cursor-pointer inline-flex size-6 items-center justify-center rounded text-ink-2 hover:bg-sheet hover:text-ink"
                                    ><flux:icon.eye variant="micro" /></flux:link>

                                    {{-- A real href whose accessible name is exactly
                                         "Download": the container smoke reads this link's
                                         href and fetches it to compare bytes, so it must
                                         stay a link and keep that name. --}}
                                    <flux:link
                                        :href="route('files.download', $item)"
                                        variant="subtle"
                                        :aria-label="__('Download')"
                                        class="inline-flex size-6 items-center justify-center rounded text-ink-2 hover:bg-sheet hover:text-ink"
                                    ><flux:icon.arrow-down-tray variant="micro" /></flux:link>

                                    @can('delete', $item)
                                        <flux:button
                                            variant="subtle"
                                            size="xs"
                                            icon="trash"
                                            :aria-label="__('Trash')"
                                            wire:click="trashFileRow({{ $item->id }})"
                                            wire:confirm="{{ __('Trash this file? It can be restored later from Trash.') }}"
                                            data-test="row-trash-file"
                                        />
                                    @endcan
                                </span>
                            </td>
                        </tr>
                    @empty
                        @if ($directories->isEmpty())
                            <tr>
                                <td colspan="7" class="px-2 py-16 text-center">
                                    <flux:icon.folder-open variant="outline" class="mx-auto mb-2 size-8 text-rule" />
                                    <div class="text-sm text-ink-2">{{ __('Nothing here yet.') }}</div>
                                </td>
                            </tr>
                        @endif
                    @endforelse
                </tbody>
            </table>
        </div>
        @php
            // At most one of these is ever set (selectFile()/selectDirectory()
            // each clear the other), and when neither is, the panel falls back
            // to the directory currently being browsed -- same as before this
            // item, just without the Rename/Move/Trash controls below, which
            // only ever appear for a genuinely SELECTED row.
            $subject = $selectedFile ?? $selectedDirectory ?? $directory;
        @endphp

        @if ($subject)
            <div class="w-84 shrink-0 space-y-5 overflow-auto border-l border-rule px-4 py-3">
                {{-- The one place `hold` appears besides the row glyph: a band,
                     not a badge, because custody is a property of the whole
                     file rather than a decoration on its title. --}}
                @if ($selectedFile?->legal_hold)
                    <div class="flex items-start gap-2 rounded border border-hold/30 bg-hold/5 px-3 py-2 text-sm">
                        <flux:icon.lock-closed variant="micro" class="mt-0.5 shrink-0 text-hold" />
                        <span class="text-ink">{{ __('Under legal hold. It cannot be trashed or changed until the hold is lifted.') }}</span>
                    </div>
                @endif

                <livewire:files.property-panel
                    :subject="$subject"
                    :key="$subject->getMorphClass().'-'.$subject->getKey()"
                />

                @if ($selectedFile)
                    <div class="space-y-5" data-test="file-actions">
                        <flux:link :href="route('files.download', $selectedFile)" variant="subtle" class=" inline-flex items-center gap-1.5 text-sm font-medium text-ink">
                            <flux:icon.arrow-down-tray variant="micro" class="text-ink-2" />
                            {{ __('Download') }}
                        </flux:link>

                        <div class="space-y-1.5">
                            <flux:heading level="3" class="text-xs font-medium tracking-wide text-ink-2">{{ __('Version history') }}</flux:heading>

                            @foreach ($versions as $version)
                                <div class="flex items-center gap-3 border-b border-rule/40 py-1 text-xs text-ink-2" data-test="file-version-row">
                                    <span class="num font-medium text-ink">{{ __('Version :number', ['number' => $version->version_number]) }}</span>
                                    <span class="num">{{ \Illuminate\Support\Number::fileSize($version->size) }}</span>
                                    <span class="min-w-0 flex-1 truncate">{{ $version->uploader?->name }}</span>
                                    <span class="num">{{ $version->created_at?->format('Y-m-d') }}</span>
                                    {{-- Per-version links need no extra @can: download == view, and this
                                         panel only opens after view already passed for $selectedFile,
                                         and every version shares its file's directory/period/uuid. --}}
                                    <flux:link :href="route('files.versions.download', [$selectedFile, $version])" variant="subtle" class=" hover:text-ink">{{ __('Download') }}</flux:link>
                                </div>
                            @endforeach
                        </div>

                        @can('replace', $selectedFile)
                            {{-- data-test goes on the <form>, which is plain HTML, NOT on the
                                 <flux:input>. Flux is known to forward arbitrary attributes on
                                 flux:button -- data-test="trash-file-button" below is driven by
                                 exactly that selector in a container-smoke check that has passed
                                 and been mutation-proven -- but there is no such precedent for
                                 flux:input, which renders a label/wrapper around the real
                                 <input>, and an attribute that lands on the wrapper cannot be
                                 handed to Playwright's setInputFiles(). Scoping a plain
                                 `input[type="file"]` under this form sidesteps the question
                                 entirely, and it has to be scoped to SOMETHING regardless:
                                 whenever a file is selected this is the second file input on
                                 the page, and a bare locator would trip Playwright's strict
                                 mode. --}}
                            <form wire:submit="replaceFile" class="space-y-2" data-test="replace-form">
                                <flux:input
                                    wire:model="replacement"
                                    :label="__('Replace with a new version')"
                                    type="file"
                                    size="sm"
                                />
                                {{-- Same guard as the Upload button above, for the same
                                     reason and against the same window; see its comment. --}}
                                <flux:button
                                    type="submit"
                                    size="sm"
                                    data-test="replace-file-button"
                                    wire:loading.attr="disabled"
                                    wire:target="replacement"
                                >{{ __('Replace') }}</flux:button>
                            </form>
                        @endcan

                        @can('update', $selectedFile)
                            <form wire:submit="renameFile" class="space-y-2">
                                <flux:input wire:model="renameValue" :label="__('Name')" type="text" size="sm" />
                                <flux:button type="submit" size="sm">{{ __('Rename') }}</flux:button>
                            </form>
                        @endcan

                        @if ($moveFileDestinations->isNotEmpty())
                            <form wire:submit="moveFile" class="space-y-2">
                                <flux:select wire:model="moveFileDestinationId" :label="__('Move to')" size="sm">
                                    <flux:select.option value="">{{ __('-- choose a directory --') }}</flux:select.option>
                                    @foreach ($moveFileDestinations as $destination)
                                        <flux:select.option :value="$destination->id">{{ $destination->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button type="submit" size="sm">{{ __('Move') }}</flux:button>
                            </form>
                        @endif

                        <div class="flex flex-wrap gap-2 border-t border-rule pt-4">
                            @can('delete', $selectedFile)
                                {{-- Plain, not `danger`: see the bulk-trash comment above. --}}
                                <flux:button
                                    size="sm"
                                    wire:click="trashFile"
                                    wire:confirm="{{ __('Trash this file? It can be restored later from Trash.') }}"
                                    data-test="trash-file-button"
                                >
                                    {{ __('Trash') }}
                                </flux:button>
                            @endcan

                            @can('legalHold', $selectedFile)
                                @if ($selectedFile->legal_hold)
                                    <flux:button size="sm" wire:click="setLegalHold(false)" data-test="clear-legal-hold-button">
                                        {{ __('Clear legal hold') }}
                                    </flux:button>
                                @else
                                    <flux:button size="sm" wire:click="setLegalHold(true)" data-test="set-legal-hold-button">
                                        {{ __('Set legal hold') }}
                                    </flux:button>
                                @endif
                            @endcan
                        </div>
                    </div>
                @elseif ($selectedDirectory)
                    <div class="space-y-5" data-test="directory-actions">
                        @can('update', $selectedDirectory)
                            <form wire:submit="renameDirectory" class="space-y-2">
                                <flux:input wire:model="renameValue" :label="__('Name')" type="text" size="sm" />
                                <flux:button type="submit" size="sm">{{ __('Rename') }}</flux:button>
                            </form>
                        @endcan

                        @can('move', [$selectedDirectory, null])
                            <form wire:submit="moveDirectory" class="space-y-2">
                                <flux:select wire:model="moveDirectoryDestinationId" :label="__('Move to')" size="sm">
                                    <flux:select.option value="">{{ __('-- root --') }}</flux:select.option>
                                    @foreach ($moveDirectoryDestinations as $destination)
                                        <flux:select.option :value="$destination->id">{{ $destination->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button type="submit" size="sm">{{ __('Move') }}</flux:button>
                            </form>
                        @endcan

                        @can('delete', $selectedDirectory)
                            {{-- Plain, not `danger`: see the bulk-trash comment above. --}}
                            <flux:button
                                size="sm"
                                wire:click="trashDirectory"
                                wire:confirm="{{ __('Trash this directory and everything beneath it? It can be restored later from Trash.') }}"
                                data-test="trash-directory-button"
                            >
                                {{ __('Trash') }}
                            </flux:button>
                        @endcan

                        {{-- item/directory-access-ui (issue #14): a non-manager must see NO
                             control at all, not a disabled one -- @can is what makes this
                             block absent from the response rather than merely hidden by CSS. --}}
                        @can('manageAccess', $selectedDirectory)
                            <div class="space-y-3 border-t border-rule pt-4" data-test="directory-access-panel">
                                <flux:heading level="3" class="text-xs font-medium tracking-wide text-ink-2">{{ __('Access') }}</flux:heading>

                                {{-- A plain <ul>, not flux:table -- rule F: flux:table does not
                                     exist in the free tier and fails to resolve in the built
                                     image while still rendering fine under the test renderer. --}}
                                <ul data-test="directory-grant-list" class="space-y-1">
                                    @forelse ($directoryGrants as $grant)
                                        <li class="flex items-center justify-between gap-3 border-b border-rule/40 py-1 text-xs" data-test="directory-grant-row" data-grant-id="{{ $grant->id }}">
                                            <span class="min-w-0 truncate text-ink-2">
                                                <span class="text-ink">{{ $grant->grantee?->email ?? __('(deleted user)') }}</span>
                                                &mdash; {{ $grant->level->value }}
                                            </span>
                                            {{-- flux:button, not a plain <button> -- wire:click,
                                                 wire:confirm and data-test together on flux:button
                                                 are already the exact combination
                                                 trash-directory-button above uses, which the
                                                 container smoke has already proven Flux forwards
                                                 (rule E). data-grant-id lives on the plain <li>
                                                 above for the smoke to select this row by. --}}
                                            <flux:button
                                                size="xs"
                                                wire:click="revokeAccess({{ $grant->id }})"
                                                wire:confirm="{{ __('Revoke this grant?') }}"
                                                data-test="revoke-access-button"
                                            >
                                                {{ __('Revoke') }}
                                            </flux:button>
                                        </li>
                                    @empty
                                        <li data-test="directory-grant-empty" class="py-1 text-xs text-ink-2">{{ __('Nobody else has been granted access.') }}</li>
                                    @endforelse
                                </ul>

                                <form wire:submit="grantAccess" class="space-y-2" data-test="grant-access-form">
                                    <flux:input
                                        wire:model="grantEmail"
                                        :label="__('Grant access to (email)')"
                                        type="email"
                                        size="sm"
                                    />
                                    <flux:select wire:model="grantLevel" :label="__('Level')" size="sm">
                                        @foreach (\App\Enums\AccessLevel::cases() as $level)
                                            <flux:select.option :value="$level->value">{{ $level->value }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:button type="submit" size="sm" data-test="grant-access-button">{{ __('Grant') }}</flux:button>
                                </form>
                            </div>
                        @endcan
                    </div>
                @endif
            </div>
        @endif

    {{-- File preview. Its open/closed state lives on the server, in
         previewFileId, so the dialog is simply not rendered when nothing is
         being previewed and `dismiss` routes Escape and the scrim through
         $wire.closePreview() -- the same path the close button takes.

         previewFile() re-authorises on every render rather than trusting the
         id it was opened with: access can be revoked between opening a preview
         and the next round trip, and a dialog left hanging open is exactly
         where that would go unnoticed. --}}
    @php
        $previewing = $this->previewFile();
    @endphp

    @if ($previewing)
        <x-modal
            state="true"
            size="full"
            dismiss="$wire.closePreview()"
            :title="$previewing->name"
            test="preview-modal"
        >
            {{-- Arrow keys step through the listing, which is what every
                 viewer does and what a person will try first. Bound on the
                 window rather than the panel: focus sits on whatever was
                 clicked, and a preview nobody has tabbed into would otherwise
                 ignore the keys entirely. --}}
            <div
                x-data
                x-on:keydown.window.arrow-right.prevent="$wire.previewStep(1)"
                x-on:keydown.window.arrow-left.prevent="$wire.previewStep(-1)"
                class="hidden"
            ></div>

            <x-slot:controls>
                @php
                    $position = $this->previewPosition();
                @endphp

                @if ($position)
                    <span class="num text-sm text-ink-2" data-test="preview-position">
                        {{ __(':position of :total', ['position' => $position[0], 'total' => $position[1]]) }}
                    </span>

                    <flux:button
                        size="sm"
                        variant="subtle"
                        icon="chevron-left"
                        :aria-label="__('Previous file')"
                        :disabled="$position[0] === 1"
                        wire:click="previewStep(-1)"
                        data-test="preview-previous"
                    />

                    <flux:button
                        size="sm"
                        variant="subtle"
                        icon="chevron-right"
                        :aria-label="__('Next file')"
                        :disabled="$position[0] === $position[1]"
                        wire:click="previewStep(1)"
                        data-test="preview-next"
                    />
                @endif

                <flux:button
                    size="sm"
                    icon="arrow-down-tray"
                    :href="route('files.download', $previewing)"
                    data-test="preview-download"
                >{{ __('Download') }}</flux:button>
            </x-slot:controls>

            @php
                $mime = $previewing->mime ?? '';
                // files.preview, not files.download: download answers
                // Content-Disposition: attachment (an <iframe> pointed at that
                // downloads instead of showing) and may redirect to a presigned
                // URL on a host the browser cannot reach -- which is the
                // container exactly. See FilePreviewController.
                $source = route('files.preview', $previewing);
                $version = $previewing->currentVersion;
            @endphp

            {{-- Content and properties side by side: looking at a document and
                 checking what it is are the same task, and making the second
                 one a separate trip defeats the preview. --}}
            <div class="flex h-full min-h-0 gap-4">
                <div class="flex min-h-0 min-w-0 flex-1 items-center justify-center">
                    @if ($version === null)
                        {{-- A file row whose version is missing has no bytes to
                             show. Without this the frame would point at
                             FilePreviewController, which answers 404 for exactly
                             this case, and the dialog would render that inside
                             itself -- reading as the preview being broken rather
                             than the file being empty. --}}
                        <div class="flex flex-col items-center gap-3 text-center" data-test="preview-unavailable">
                            <flux:icon.exclamation-triangle variant="outline" class="size-10 text-attention" />
                            <p class="text-sm text-ink">{{ __('This file has no stored version yet.') }}</p>
                            <p class="max-w-sm text-xs text-ink-2">{{ __('Nothing was uploaded for it, or the upload did not finish. Replace it from the detail panel to give it contents.') }}</p>
                        </div>
                    @elseif (str_starts_with($mime, 'image/'))
                        {{-- Contained rather than cropped: a scan is read, not
                             admired, and cutting its edges off hides exactly the
                             margins that carry stamps and signatures. --}}
                        <img
                            src="{{ $source }}"
                            alt="{{ $previewing->name }}"
                            class="max-h-full max-w-full object-contain"
                            data-test="preview-image"
                        />
                    @elseif ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
                        {{-- Converted in the browser by mammoth: nothing renders
                             a .docx natively, and converting server-side would
                             mean LibreOffice in the image, which this project
                             has deferred. The result is somebody's uploaded
                             document, so it goes into a sandboxed frame rather
                             than into this page's DOM. --}}
                        <div
                            class="h-full w-full"
                            x-data="filePreview({ url: @js($source), mime: @js($mime), name: @js($previewing->name), kind: 'word' })"
                            data-test="preview-word"
                        >
                            <template x-if="state === 'loading'">
                                <div class="flex h-full items-center justify-center gap-2 text-sm text-ink-2">
                                    <flux:icon.arrow-path variant="micro" class="animate-spin" />
                                    {{ __('Converting document…') }}
                                </div>
                            </template>

                            <template x-if="state === 'failed'">
                                <div class="flex h-full flex-col items-center justify-center gap-2 text-center">
                                    <flux:icon.exclamation-triangle variant="outline" class="size-8 text-attention" />
                                    <p class="text-sm text-ink">{{ __('This document could not be converted.') }}</p>
                                    <p class="text-xs text-ink-2" x-text="failure"></p>
                                </div>
                            </template>

                            <iframe
                                x-show="state === 'ready'"
                                x-bind:srcdoc="documentFrame"
                                sandbox=""
                                title="{{ $previewing->name }}"
                                class="h-full w-full rounded border border-rule bg-sheet"
                                data-test="preview-word-frame"
                            ></iframe>
                        </div>
                    @elseif (str_starts_with($mime, 'text/') || str_contains($mime, 'json') || str_contains($mime, 'xml'))
                        {{-- Text gets its source, highlighted. Markup gets both:
                             what it renders as, and what it says. A stored
                             document is evidence, so the source is never
                             reformatted -- prettifying it would show something
                             other than what is filed. --}}
                        @php($rendersAsPage = str_contains($mime, 'html'))

                        <div
                            class="flex h-full w-full flex-col"
                            x-data="filePreview({ url: @js($source), mime: @js($mime), name: @js($previewing->name), kind: 'text', initialTab: @js($rendersAsPage ? 'preview' : 'code') })"
                            data-test="preview-text"
                        >
                            @if ($rendersAsPage)
                                <div class="mb-2 flex shrink-0 items-center gap-1 border-b border-rule">
                                    @foreach ([['preview', __('Preview')], ['code', __('Code')]] as [$key, $label])
                                        <button
                                            type="button"
                                            x-on:click="show(@js($key))"
                                            x-bind:class="tab === @js($key) ? 'border-ink text-ink' : 'border-transparent text-ink-2 hover:text-ink'"
                                            class="-mb-px border-b-2 px-3 py-2 text-sm"
                                            data-test="preview-tab-{{ $key }}"
                                        >{{ $label }}</button>
                                    @endforeach
                                </div>

                                <iframe
                                    x-show="tab === 'preview'"
                                    src="{{ $source }}"
                                    sandbox=""
                                    title="{{ $previewing->name }}"
                                    class="min-h-0 w-full flex-1 rounded border border-rule bg-sheet"
                                    data-test="preview-frame"
                                ></iframe>
                            @endif

                            <div
                                @if ($rendersAsPage) x-show="tab === 'code'" @endif
                                class="min-h-0 flex-1 overflow-auto rounded border border-rule bg-chrome"
                                data-test="preview-code"
                            >
                                <template x-if="state === 'loading'">
                                    <div class="p-4 text-sm text-ink-2">{{ __('Loading…') }}</div>
                                </template>

                                <template x-if="state === 'failed'">
                                    <div class="p-4 text-sm text-attention" x-text="failure"></div>
                                </template>

                                <pre class="overflow-auto p-4 text-xs leading-relaxed"><code class="hljs" x-html="code"></code></pre>
                            </div>
                        </div>
                    @elseif ($mime === 'application/pdf')
                        {{-- An iframe, so the browser's own PDF and text viewers
                             do the work. Bundling a JavaScript PDF renderer would
                             add megabytes to an image that already ships MinIO
                             and tesseract, to show what every browser shows.

                             sandbox="" because these are somebody's uploaded
                             bytes served same-origin. --}}
                        <iframe
                            src="{{ $source }}"
                            sandbox=""
                            title="{{ $previewing->name }}"
                            class="h-full min-h-[60vh] w-full rounded border border-rule bg-chrome"
                            data-test="preview-frame"
                        ></iframe>
                    @else
                        <div class="flex flex-col items-center gap-3 text-center" data-test="preview-unsupported">
                            <flux:icon.document variant="outline" class="size-10 text-rule" />
                            <p class="text-sm text-ink-2">
                                {{ __('No preview for :type files. Download it to open in another application.', ['type' => $mime ?: __('these')]) }}
                            </p>
                        </div>
                    @endif
                </div>

                <aside class="hidden w-72 shrink-0 overflow-auto border-l border-rule pl-4 lg:block" data-test="preview-properties">
                    @if ($previewing->legal_hold)
                        <div class="mb-4 flex items-start gap-2 rounded border border-hold/30 bg-hold/5 px-3 py-2 text-xs">
                            <flux:icon.lock-closed variant="micro" class="mt-0.5 shrink-0 text-hold" />
                            <span class="text-ink">{{ __('Under legal hold.') }}</span>
                        </div>
                    @endif

                    <dl class="space-y-2.5 text-xs">
                        @foreach ([
                            __('Type') => $mime ?: __('Unknown'),
                            __('Size') => $version ? \Illuminate\Support\Number::fileSize($version->size) : '—',
                            __('Version') => $version?->version_number ? '#'.$version->version_number : '—',
                            __('Owner') => $previewing->creator?->name ?? '—',
                            __('Modified') => $previewing->updated_at?->format('Y-m-d H:i') ?? '—',
                            __('Period') => $previewing->period_year ? sprintf('%04d-%02d', $previewing->period_year, $previewing->period_month) : '—',
                        ] as $label => $value)
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="shrink-0 text-ink-2">{{ $label }}</dt>
                                <dd class="num min-w-0 truncate text-right text-ink">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    {{-- The governed properties for this file, the same component
                         the detail panel renders. Its own key, because two
                         instances of a Livewire component sharing one key are one
                         component as far as Livewire is concerned. --}}
                    <div class="mt-4 border-t border-rule pt-4">
                        <livewire:files.property-panel
                            :subject="$previewing"
                            :key="'preview-'.$previewing->getMorphClass().'-'.$previewing->getKey()"
                        />
                    </div>
                </aside>
            </div>

        </x-modal>
    @endif
    </div>
</section>
