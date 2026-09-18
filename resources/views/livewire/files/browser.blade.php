<section class="w-full space-y-6">
    @if ($breadcrumbs->isNotEmpty())
        {{-- Every ancestor the viewer may VIEW, root first, current directory
             last -- and no others: an ancestor with no grant anywhere on it
             (a grant made directly on a NESTED directory, per
             DirectoryAccess) is not in $breadcrumbs at all, so there is
             nothing here for the view itself to filter. See
             Browser::breadcrumbTrail(). The final crumb (the directory being
             browsed) carries no href, matching the read-only "you are here"
             convention the rest of the app already used. --}}
        <div class="flex items-center justify-between">
            <flux:breadcrumbs>
                @foreach ($breadcrumbs as $index => $crumb)
                    @if ($index === $breadcrumbs->count() - 1)
                        <flux:breadcrumbs.item>
                            <span data-test="breadcrumb-item">{{ $crumb->name }}</span>
                        </flux:breadcrumbs.item>
                    @else
                        <flux:breadcrumbs.item :href="route('files.browse', $crumb)" wire:navigate>
                            <span data-test="breadcrumb-item">{{ $crumb->name }}</span>
                        </flux:breadcrumbs.item>
                    @endif
                @endforeach
            </flux:breadcrumbs>
        </div>
    @endif

    <div class="flex gap-8">
        {{-- The Files sidebar (spec §10): Home pinned first, then the
             viewer's reach roots and their viewable descendants --
             $sidebarTree, resolved ENTIRELY by DirectoryAccess::reachTree()
             (render()'s own comment says why: filtering happens in the
             query, never here). Collapse state lives in localStorage,
             keyed per directory id, wrapped in try/catch because it throws
             in a private window -- see CLAUDE.md and the doccum design
             spec §10a. --}}
        <aside
            class="w-56 shrink-0 space-y-4 border-r pr-4"
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
                        class="text-sm text-zinc-800 hover:underline dark:text-white"
                    >{{ __('Home') }}</a>
                </div>
            @endif

            <ul data-test="sidebar-tree" class="space-y-1">
                @include('livewire.files.partials.directory-tree', ['nodes' => $sidebarTree])
            </ul>
        </aside>

        <div class="flex-1 space-y-6">
            {{-- item/trash-view (issue #15): the one path spec §10 names into
                 the Trash page ("Reached from Files"). Plain <div> wrapping a
                 real <a> (flux:link renders one when it has a real href),
                 not a data-test on flux:link itself -- Flux is only KNOWN to
                 forward arbitrary attributes on flux:button (CLAUDE.md). --}}
            <div class="flex justify-end" data-test="trash-link">
                <flux:link :href="route('trash')" wire:navigate>{{ __('Trash') }}</flux:link>
            </div>

            {{-- data-test scopes the container-smoke's own navigation: at the
                 root of the browser ($directory === null) this list and the
                 sidebar both render the SAME reach roots (issue #99), so a
                 directory's name is no longer a unique link on the landing
                 page and any locator has to say which of the two it means. --}}
            <div class="space-y-2" data-test="directories-list">
                <flux:heading level="2">{{ __('Directories') }}</flux:heading>

                @forelse ($directories as $item)
                    <div class="flex items-center gap-4">
                        <flux:link :href="route('files.browse', $item)" wire:navigate>{{ $item->name }}</flux:link>
                        <flux:link wire:click="selectDirectory({{ $item->id }})" class="cursor-pointer text-sm">{{ __('Details') }}</flux:link>
                    </div>
                @empty
                    <flux:text>{{ __('No subdirectories.') }}</flux:text>
                @endforelse
            </div>

            <div class="space-y-2">
                <flux:heading level="2">{{ __('Files') }}</flux:heading>

                @if (! empty($selectedIds))
                    {{-- Only ever shown once something is ticked, and the count is what
                         proves a click actually reached selectRow() -- a resting "0
                         selected" label would pass whether or not selection worked at
                         all (CLAUDE.md: prefer an assertion that requires the feature
                         to DO something). --}}
                    <div class="flex items-center gap-4" data-test="bulk-actions">
                        <flux:text>{{ trans_choice(':count file selected|:count files selected', count($selectedIds), ['count' => count($selectedIds)]) }}</flux:text>
                        <flux:button
                            variant="danger"
                            wire:click="bulkTrash"
                            wire:confirm="{{ __('Trash the selected files? They can be restored later from Trash.') }}"
                            data-test="bulk-trash-button"
                        >
                            {{ __('Trash selected') }}
                        </flux:button>
                    </div>
                @endif

                <table data-test="files-table" class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th class="w-8"><span class="sr-only">{{ __('Select') }}</span></th>
                            {{-- Every header cell sorts by the SAME mechanism, sortBy(),
                                 which resolves the column against Browser::SORTABLE --
                                 never the raw string a click sends -- so there is nothing
                                 here for the view itself to whitelist. --}}
                            <th><flux:link wire:click="sortBy('name')" class="cursor-pointer">{{ __('Name') }}</flux:link></th>
                            <th><flux:link wire:click="sortBy('owner')" class="cursor-pointer">{{ __('Owner') }}</flux:link></th>
                            <th><flux:link wire:click="sortBy('modified')" class="cursor-pointer">{{ __('Modified') }}</flux:link></th>
                            <th><flux:link wire:click="sortBy('size')" class="cursor-pointer">{{ __('Size') }}</flux:link></th>
                            <th class="w-24"></th>
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
                            <tr
                                data-test="file-row"
                                data-file-id="{{ $item->id }}"
                                x-on:click="$wire.selectRow({{ $item->id }}, $event.shiftKey, $event.ctrlKey || $event.metaKey)"
                                @class([
                                    'cursor-pointer',
                                    'bg-zinc-100 dark:bg-zinc-700' => in_array($item->id, $selectedIds, true),
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
                                <td>
                                    <input
                                        type="checkbox"
                                        data-test="file-row-checkbox"
                                        aria-label="{{ __('Select :name', ['name' => $item->name]) }}"
                                        @checked(in_array($item->id, $selectedIds, true))
                                        x-on:click.stop="$wire.selectRow({{ $item->id }}, $event.shiftKey, ! $event.shiftKey)"
                                    />
                                </td>
                                <td>
                                    <flux:link wire:click="selectFile({{ $item->id }})" class="cursor-pointer">{{ $item->name }}</flux:link>
                                </td>
                                <td>{{ $item->creator?->name }}</td>
                                <td>{{ $item->updated_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ \Illuminate\Support\Number::fileSize($item->size) }}</td>
                                <td><flux:link :href="route('files.download', $item)">{{ __('Download') }}</flux:link></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><flux:text>{{ __('No files.') }}</flux:text></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <form wire:submit="createDirectory" class="flex items-end gap-4">
                <flux:input wire:model="newDirectoryName" :label="__('New folder')" type="text" />
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
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
                <form wire:submit="store" class="flex items-end gap-4" data-test="upload-form">
                    <flux:input wire:model="upload" :label="__('Upload a file')" type="file" />
                    <flux:button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                        wire:target="upload"
                    >{{ __('Upload') }}</flux:button>
                </form>
            @endif
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
            <div class="w-80 shrink-0 border-l pl-8 space-y-6">
                <livewire:files.property-panel
                    :subject="$subject"
                    :key="$subject->getMorphClass().'-'.$subject->getKey()"
                />

                @if ($selectedFile)
                    <div class="space-y-4" data-test="file-actions">
                        <flux:link :href="route('files.download', $selectedFile)">{{ __('Download') }}</flux:link>

                        <div class="space-y-2">
                            <flux:heading level="3">{{ __('Version history') }}</flux:heading>

                            @foreach ($versions as $version)
                                <div class="flex items-center gap-4 text-sm" data-test="file-version-row">
                                    <span>{{ __('Version :number', ['number' => $version->version_number]) }}</span>
                                    <span>{{ \Illuminate\Support\Number::fileSize($version->size) }}</span>
                                    <span>{{ $version->uploader?->name }}</span>
                                    <span>{{ $version->created_at?->format('Y-m-d') }}</span>
                                    {{-- Per-version links need no extra @can: download == view, and this
                                         panel only opens after view already passed for $selectedFile,
                                         and every version shares its file's directory/period/uuid. --}}
                                    <flux:link :href="route('files.versions.download', [$selectedFile, $version])">{{ __('Download') }}</flux:link>
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
                            <form wire:submit="replaceFile" class="flex items-end gap-2" data-test="replace-form">
                                <flux:input
                                    wire:model="replacement"
                                    :label="__('Replace with a new version')"
                                    type="file"
                                />
                                {{-- Same guard as the Upload button above, for the same
                                     reason and against the same window; see its comment. --}}
                                <flux:button
                                    type="submit"
                                    data-test="replace-file-button"
                                    wire:loading.attr="disabled"
                                    wire:target="replacement"
                                >{{ __('Replace') }}</flux:button>
                            </form>
                        @endcan

                        @can('update', $selectedFile)
                            <form wire:submit="renameFile" class="flex items-end gap-2">
                                <flux:input wire:model="renameValue" :label="__('Name')" type="text" />
                                <flux:button type="submit">{{ __('Rename') }}</flux:button>
                            </form>
                        @endcan

                        @if ($moveFileDestinations->isNotEmpty())
                            <form wire:submit="moveFile" class="flex items-end gap-2">
                                <flux:select wire:model="moveFileDestinationId" :label="__('Move to')">
                                    <flux:select.option value="">{{ __('-- choose a directory --') }}</flux:select.option>
                                    @foreach ($moveFileDestinations as $destination)
                                        <flux:select.option :value="$destination->id">{{ $destination->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button type="submit">{{ __('Move') }}</flux:button>
                            </form>
                        @endif

                        @can('delete', $selectedFile)
                            <flux:button
                                variant="danger"
                                wire:click="trashFile"
                                wire:confirm="{{ __('Trash this file? It can be restored later from Trash.') }}"
                                data-test="trash-file-button"
                            >
                                {{ __('Trash') }}
                            </flux:button>
                        @endcan

                        @can('legalHold', $selectedFile)
                            @if ($selectedFile->legal_hold)
                                <flux:button wire:click="setLegalHold(false)" data-test="clear-legal-hold-button">
                                    {{ __('Clear legal hold') }}
                                </flux:button>
                            @else
                                <flux:button wire:click="setLegalHold(true)" data-test="set-legal-hold-button">
                                    {{ __('Set legal hold') }}
                                </flux:button>
                            @endif
                        @endcan
                    </div>
                @elseif ($selectedDirectory)
                    <div class="space-y-4" data-test="directory-actions">
                        @can('update', $selectedDirectory)
                            <form wire:submit="renameDirectory" class="flex items-end gap-2">
                                <flux:input wire:model="renameValue" :label="__('Name')" type="text" />
                                <flux:button type="submit">{{ __('Rename') }}</flux:button>
                            </form>
                        @endcan

                        @can('move', [$selectedDirectory, null])
                            <form wire:submit="moveDirectory" class="flex items-end gap-2">
                                <flux:select wire:model="moveDirectoryDestinationId" :label="__('Move to')">
                                    <flux:select.option value="">{{ __('-- root --') }}</flux:select.option>
                                    @foreach ($moveDirectoryDestinations as $destination)
                                        <flux:select.option :value="$destination->id">{{ $destination->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button type="submit">{{ __('Move') }}</flux:button>
                            </form>
                        @endcan

                        @can('delete', $selectedDirectory)
                            <flux:button
                                variant="danger"
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
                            <div class="space-y-3 border-t pt-4" data-test="directory-access-panel">
                                <flux:heading level="3">{{ __('Access') }}</flux:heading>

                                {{-- A plain <ul>, not flux:table -- rule F: flux:table does not
                                     exist in the free tier and fails to resolve in the built
                                     image while still rendering fine under the test renderer. --}}
                                <ul data-test="directory-grant-list" class="space-y-2 text-sm">
                                    @forelse ($directoryGrants as $grant)
                                        <li class="flex items-center justify-between gap-4" data-test="directory-grant-row" data-grant-id="{{ $grant->id }}">
                                            <span>
                                                {{ $grant->grantee?->email ?? __('(deleted user)') }}
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
                                                variant="danger"
                                                wire:click="revokeAccess({{ $grant->id }})"
                                                wire:confirm="{{ __('Revoke this grant?') }}"
                                                data-test="revoke-access-button"
                                            >
                                                {{ __('Revoke') }}
                                            </flux:button>
                                        </li>
                                    @empty
                                        <li data-test="directory-grant-empty"><flux:text>{{ __('Nobody else has been granted access.') }}</flux:text></li>
                                    @endforelse
                                </ul>

                                <form wire:submit="grantAccess" class="flex items-end gap-2" data-test="grant-access-form">
                                    <flux:input
                                        wire:model="grantEmail"
                                        :label="__('Grant access to (email)')"
                                        type="email"
                                    />
                                    <flux:select wire:model="grantLevel" :label="__('Level')">
                                        @foreach (\App\Enums\AccessLevel::cases() as $level)
                                            <flux:select.option :value="$level->value">{{ $level->value }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:button type="submit" data-test="grant-access-button">{{ __('Grant') }}</flux:button>
                                </form>
                            </div>
                        @endcan
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
