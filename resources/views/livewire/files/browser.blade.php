<section class="w-full">
    {{-- Toolbar (design plan §3): 44px, breadcrumb left, Trash right. The
         breadcrumb is the only navigation on this row, so it takes the whole
         left side rather than sharing space with a heading that repeats it. --}}
    <div class="flex h-11 items-center justify-between gap-4 border-b border-rule px-4">
        @if ($breadcrumbs->isNotEmpty())
            {{-- Every ancestor the viewer may VIEW, root first, current directory
                 last -- and no others: an ancestor with no grant anywhere on it
                 (a grant made directly on a NESTED directory, per
                 DirectoryAccess) is not in $breadcrumbs at all, so there is
                 nothing here for the view itself to filter. See
                 Browser::breadcrumbTrail(). The final crumb (the directory being
                 browsed) carries no href, matching the read-only "you are here"
                 convention the rest of the app already used. --}}
            <flux:breadcrumbs>
                @foreach ($breadcrumbs as $index => $crumb)
                    @if ($index === $breadcrumbs->count() - 1)
                        <flux:breadcrumbs.item>
                            <span data-test="breadcrumb-item" class="font-medium text-ink">{{ $crumb->name }}</span>
                        </flux:breadcrumbs.item>
                    @else
                        <flux:breadcrumbs.item :href="route('files.browse', $crumb)" wire:navigate>
                            <span data-test="breadcrumb-item">{{ $crumb->name }}</span>
                        </flux:breadcrumbs.item>
                    @endif
                @endforeach
            </flux:breadcrumbs>
        @else
            <span></span>
        @endif

        {{-- item/trash-view (issue #15): the one path spec §10 names into
             the Trash page ("Reached from Files"). Plain <div> wrapping a
             real <a> (flux:link renders one when it has a real href),
             not a data-test on flux:link itself -- Flux is only KNOWN to
             forward arbitrary attributes on flux:button (CLAUDE.md). --}}
        <div data-test="trash-link">
            <flux:link :href="route('trash')" wire:navigate variant="subtle" class=" inline-flex items-center gap-1.5 text-sm text-ink-2 hover:text-ink">
                <flux:icon.trash variant="micro" />
                {{ __('Trash') }}
            </flux:link>
        </div>
    </div>

    <div class="flex items-stretch">
        {{-- The Files sidebar (spec §10): Home pinned first, then the
             viewer's reach roots and their viewable descendants --
             $sidebarTree, resolved ENTIRELY by DirectoryAccess::reachTree()
             (render()'s own comment says why: filtering happens in the
             query, never here). Collapse state lives in localStorage,
             keyed per directory id, wrapped in try/catch because it throws
             in a private window -- see CLAUDE.md and the doccum design
             spec §10a. --}}
        <aside
            class="w-60 shrink-0 space-y-3 border-r border-rule bg-chrome px-3 py-4"
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
                <div data-test="sidebar-home">
                    <a
                        href="{{ route('files.browse', $homeDirectory) }}"
                        wire:navigate
                        aria-label="{{ __('Home directory') }}"
                        class="flex h-8 items-center gap-2 rounded px-2 text-sm font-medium text-ink hover:bg-sheet"
                    >
                        <flux:icon.home variant="micro" class="shrink-0 text-ink-2" aria-hidden="true" />
                        {{ __('Home') }}
                    </a>
                </div>
            @endif

            <ul data-test="sidebar-tree" class="space-y-0.5">
                @include('livewire.files.partials.directory-tree', ['nodes' => $sidebarTree])
            </ul>
        </aside>

        <div class="min-w-0 flex-1 px-4 py-4">
            {{-- data-test scopes the container-smoke's own navigation: at the
                 root of the browser ($directory === null) this list and the
                 sidebar both render the SAME reach roots (issue #99), so a
                 directory's name is no longer a unique link on the landing
                 page and any locator has to say which of the two it means. --}}
            <div data-test="directories-list">
                @forelse ($directories as $item)
                    <div class="group flex h-8 items-center gap-2 border-b border-rule/40 px-2 text-sm">
                        <flux:icon.folder variant="micro" class="shrink-0 text-ink-2" />
                        <flux:link :href="route('files.browse', $item)" wire:navigate variant="ghost" class=" min-w-0 flex-1 truncate font-medium text-ink">{{ $item->name }}</flux:link>
                        <flux:link
                            wire:click="selectDirectory({{ $item->id }})"
                            variant="subtle" class=" cursor-pointer text-xs text-ink-2 opacity-0 transition-opacity group-hover:opacity-100 hover:text-ink"
                        >{{ __('Details') }}</flux:link>
                    </div>
                @empty
                    @if ($files->isEmpty())
                        <div class="px-2 py-8 text-center text-sm text-ink-2">{{ __('No subdirectories.') }}</div>
                    @endif
                @endforelse
            </div>

            @if (! empty($selectedIds))
                {{-- Only ever shown once something is ticked, and the count is what
                     proves a click actually reached selectRow() -- a resting "0
                     selected" label would pass whether or not selection worked at
                     all (CLAUDE.md: prefer an assertion that requires the feature
                     to DO something). --}}
                <div class="mt-3 flex h-9 items-center gap-3 rounded border border-select/30 bg-select/5 px-3" data-test="bulk-actions">
                    <flux:text class="text-sm font-medium text-ink">{{ trans_choice(':count file selected|:count files selected', count($selectedIds), ['count' => count($selectedIds)]) }}</flux:text>
                    {{-- No `danger` variant: red in doccum means legal hold and
                         nothing else (design plan §1). Trashing is reversible --
                         Trash restores -- so it is an ordinary action, and the
                         wire:confirm below carries the weight instead. --}}
                    <flux:button
                        size="sm"
                        wire:click="bulkTrash"
                        wire:confirm="{{ __('Trash the selected files? They can be restored later from Trash.') }}"
                        data-test="bulk-trash-button"
                    >
                        {{ __('Trash selected') }}
                    </flux:button>
                </div>
            @endif

            <table data-test="files-table" class="mt-3 w-full table-fixed text-left text-sm">
                <thead>
                    <tr class="border-b border-rule text-xs text-ink-2">
                        <th class="w-8 py-1.5"><span class="sr-only">{{ __('Select') }}</span></th>
                        <th class="w-5"></th>
                        {{-- Every header cell sorts by the SAME mechanism, sortBy(),
                             which resolves the column against Browser::SORTABLE --
                             never the raw string a click sends -- so there is nothing
                             here for the view itself to whitelist. --}}
                        <th class="py-1.5 font-medium"><flux:link wire:click="sortBy('name')" variant="subtle" class=" cursor-pointer hover:text-ink">{{ __('Name') }}</flux:link></th>
                        <th class="w-40 py-1.5 font-medium"><flux:link wire:click="sortBy('owner')" variant="subtle" class=" cursor-pointer hover:text-ink">{{ __('Owner') }}</flux:link></th>
                        <th class="w-40 py-1.5 font-medium"><flux:link wire:click="sortBy('modified')" variant="subtle" class=" cursor-pointer hover:text-ink">{{ __('Modified') }}</flux:link></th>
                        <th class="w-24 py-1.5 text-right font-medium"><flux:link wire:click="sortBy('size')" variant="subtle" class=" cursor-pointer hover:text-ink">{{ __('Size') }}</flux:link></th>
                        <th class="w-24 py-1.5"></th>
                    </tr>
                </thead>
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
                            data-test="file-row"
                            data-file-id="{{ $item->id }}"
                            x-on:click="$wire.selectRow({{ $item->id }}, $event.shiftKey, $event.ctrlKey || $event.metaKey)"
                            @class([
                                'h-8 cursor-pointer border-b border-rule/40',
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
                                    <flux:link wire:click="selectFile({{ $item->id }})" variant="ghost" class=" min-w-0 cursor-pointer truncate text-ink">{{ $item->name }}</flux:link>
                                </div>
                            </td>
                            <td class="truncate py-1 text-ink-2">{{ $item->creator?->name }}</td>
                            <td class="num py-1 text-ink-2">{{ $item->updated_at?->format('Y-m-d H:i') }}</td>
                            <td class="num py-1 text-right text-ink-2">{{ \Illuminate\Support\Number::fileSize($item->size) }}</td>
                            <td class="py-1 text-right">
                                <flux:link :href="route('files.download', $item)" variant="subtle" class=" text-xs text-ink-2 hover:text-ink">{{ __('Download') }}</flux:link>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-2 py-8 text-center text-ink-2">{{ __('No files.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-6 flex flex-wrap items-end gap-6 border-t border-rule pt-4">
                <form wire:submit="createDirectory" class="flex items-end gap-2">
                    <flux:input wire:model="newDirectoryName" :label="__('New folder')" type="text" size="sm" />
                    <flux:button type="submit" size="sm">{{ __('Create') }}</flux:button>
                </form>

                @if ($directory)
                    {{-- Named for the same reason the Replace form above is: once a file is
                         selected there are two file inputs on the page, and the container
                         smoke's shared uploadAndProveStored() helper must be able to say
                         which one it means. --}}
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
                         upload endpoint, which is also what proves Flux forwarded these
                         attributes to the real <button> -- that is not assumed here. --}}
                    <form wire:submit="store" class="flex items-end gap-2" data-test="upload-form">
                        <flux:input wire:model="upload" :label="__('Upload a file')" type="file" size="sm" />
                        <flux:button
                            type="submit"
                            variant="primary"
                            size="sm"
                            wire:loading.attr="disabled"
                            wire:target="upload"
                        >{{ __('Upload') }}</flux:button>
                    </form>
                @endif
            </div>
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
            <div class="w-84 shrink-0 space-y-5 border-l border-rule px-4 py-4">
                {{-- The one place `hold` appears besides the row glyph: a band,
                     not a badge, because custody is a property of the whole
                     file rather than a decoration on its title. --}}
                @if ($selectedFile?->legal_hold)
                    <div class="flex items-start gap-2 rounded border border-hold/30 bg-hold/5 px-3 py-2 text-sm">
                        <flux:icon.lock-closed variant="micro" class="mt-0.5 shrink-0 text-hold" />
                        <span class="text-ink">{{ __('Under legal hold. It cannot be trashed or changed until the hold is lifted.') }}</span>
                    </div>
                @endif

                <div class="min-w-0 truncate text-sm font-semibold text-ink">
                    {{ $subject->name }}
                </div>

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
    </div>
</section>
