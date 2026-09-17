<section class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('files.browse')" wire:navigate>{{ __('Files') }}</flux:breadcrumbs.item>
            @if ($directory)
                <flux:breadcrumbs.item>{{ $directory->name }}</flux:breadcrumbs.item>
            @endif
        </flux:breadcrumbs>
    </div>

    <div class="flex gap-8">
        <div class="flex-1 space-y-6">
            <div class="space-y-2">
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
                <form wire:submit="store" class="flex items-end gap-4" data-test="upload-form">
                    <flux:input wire:model="upload" :label="__('Upload a file')" type="file" />
                    <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
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
                                <flux:button type="submit" data-test="replace-file-button">{{ __('Replace') }}</flux:button>
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
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
