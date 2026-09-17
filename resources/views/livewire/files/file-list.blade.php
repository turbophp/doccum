{{--
    The dense file table and the period spine (design plan §4 and §7).

    Access is already decided in FileList::mount() -- nothing here re-checks
    it; this view only renders what the component's query already filtered.

    Selection is client-side and instant (design plan §5 motion #1, "Select":
    "no animation"). No row utility class eases a background or colour
    change, and selection.js never calls `move()` -- a background change
    here happens on the same frame a click does, so a fast filer never
    watches the UI catch up.
--}}
<div x-data="{}" class="flex h-full min-w-0 flex-col bg-sheet text-ink">
    {{-- Column header: 28px, 12/400 sentence-case labels, sort buttons with aria-sort (design plan §2, §4). --}}
    <div class="flex h-7 shrink-0 items-center border-b border-rule bg-sheet px-2 text-xs text-ink-2" role="row">
        <span class="w-5 shrink-0" aria-hidden="true"></span>
        <span class="w-6 shrink-0" aria-hidden="true"></span>
        <span class="w-5 shrink-0" aria-hidden="true"></span>

        <button
            type="button"
            wire:click="sortBy('name')"
            class="min-w-[160px] flex-1 truncate pr-2 text-left font-normal text-ink-2 hover:text-ink"
            aria-sort="{{ $sort === 'name' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"
        >
            {{ __('Name') }}
        </button>

        <span class="w-24 shrink-0">{{ __('State') }}</span>

        <button
            type="button"
            wire:click="sortBy('period')"
            class="hidden w-16 shrink-0 text-left font-normal text-ink-2 hover:text-ink xl:block"
            aria-sort="{{ $sort === 'period' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"
        >
            {{ __('Period') }}
        </button>

        <span class="hidden w-32 shrink-0 min-[1440px]:block">{{ __('Owner') }}</span>

        <button
            type="button"
            wire:click="sortBy('modified')"
            class="w-32 shrink-0 text-left font-normal text-ink-2 hover:text-ink"
            aria-sort="{{ $sort === 'modified' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"
        >
            {{ __('Modified') }}
        </button>

        <button
            type="button"
            wire:click="sortBy('size')"
            class="w-[72px] shrink-0 text-right font-normal text-ink-2 hover:text-ink"
            aria-sort="{{ $sort === 'size' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"
        >
            {{ __('Size') }}
        </button>

        <span class="hidden w-10 shrink-0 text-right xl:block">{{ __('Version') }}</span>
        <span class="w-7 shrink-0" aria-hidden="true"></span>
    </div>

    {{--
        Rows, grouped under sticky period bands only while sorted by period
        (design plan §7). `data-file-grid` is what selection.js walks to
        resolve a shift-click range in the order rows actually render, which
        changes with the sort.
    --}}
    <div
        data-file-grid
        role="grid"
        aria-label="{{ __('Files') }}"
        aria-rowcount="{{ count(array_filter($rows, fn ($row) => $row['type'] === 'file')) }}"
        class="min-h-0 flex-1 overflow-y-auto"
        @click.self="$store.selection.clear()"
    >
        @forelse ($rows as $row)
            @if ($row['type'] === 'band')
                {{-- Period band: sticky, 28px, archive status and the closer's recorded counts (design plan §7). --}}
                <div
                    wire:key="band-{{ $row['year'] }}-{{ $row['month'] }}"
                    data-period-band
                    data-period="{{ sprintf('%04d-%02d', $row['year'], $row['month']) }}"
                    class="sticky top-0 z-10 flex h-7 shrink-0 items-center gap-3 border-b border-rule bg-chrome px-2 text-ink-2"
                >
                    <span class="w-5 shrink-0" aria-hidden="true"></span>

                    <span class="text-xs font-medium text-ink">{{ $row['label'] }}</span>

                    @if ($row['archived'])
                        <span class="ml-auto flex items-center gap-1 text-[11px] leading-4">
                            <flux:icon.archive-box variant="micro" class="text-ink-2" />
                            <span>{{ __('Archived :date', ['date' => $row['archived_at']]) }}</span>
                            <span class="num">{{ __(':count files, :bytes', ['count' => number_format((int) $row['file_count']), 'bytes' => $row['byte_count']]) }}</span>
                        </span>
                    @else
                        <span class="ml-auto text-[11px] leading-4">{{ __('open') }}</span>
                    @endif

                    {{-- Archived band's hatched bottom rule -- the only texture in the interface (design plan §7). `sticky` already establishes the positioning context this needs. --}}
                    @if ($row['archived'])
                        <span class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-[repeating-linear-gradient(90deg,var(--color-ink-2)_0,var(--color-ink-2)_2px,transparent_2px,transparent_4px)]" aria-hidden="true"></span>
                    @endif
                </div>
            @else
                {{-- File row: 32px default density (design plan §4). --}}
                <div
                    role="row"
                    wire:key="file-{{ $row['id'] }}"
                    data-row-id="{{ $row['id'] }}"
                    data-file-row
                    tabindex="-1"
                    aria-selected="false"
                    x-bind:aria-selected="$store.selection.isSelected({{ $row['id'] }}) ? 'true' : 'false'"
                    x-bind:class="$store.selection.isSelected({{ $row['id'] }}) ? 'bg-[color-mix(in_oklab,var(--color-select)_10%,var(--color-sheet))] dark:bg-[color-mix(in_oklab,var(--color-select)_16%,var(--color-sheet))]' : ''"
                    x-on:click="$store.selection.click({{ $row['id'] }}, $event, $el.closest('[data-file-grid]'))"
                    class="group relative flex h-8 shrink-0 items-center border-b border-rule/60 px-2 hover:bg-chrome"
                >
                    {{-- Spine gutter: a period line only while grouped; the hold tick regardless (design plan §7). --}}
                    <div class="relative flex h-full w-5 shrink-0 items-center" aria-hidden="true">
                        @if ($grouped)
                            <span
                                data-period-line
                                class="absolute inset-y-0 left-[7px] {{ $row['archived'] ? 'w-0.5 bg-ink-2' : 'w-px bg-rule' }}"
                            ></span>
                        @endif

                        @if ($row['legal_hold'])
                            <span data-hold-tick class="absolute inset-y-0 left-[13px] w-0.5 bg-hold"></span>
                        @endif
                    </div>

                    {{-- Checkbox: shown on row hover, when selected, or when any row is selected (design plan §4, "Dropbox behaviour"). --}}
                    <div class="flex w-6 shrink-0 items-center justify-center">
                        <input
                            type="checkbox"
                            aria-label="{{ __('Select :name', ['name' => $row['name']]) }}"
                            class="size-4 shrink-0 rounded-xs border-rule text-select opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                            x-bind:class="($store.selection.isSelected({{ $row['id'] }}) || $store.selection.anySelected()) ? 'opacity-100' : ''"
                            x-bind:checked="$store.selection.isSelected({{ $row['id'] }})"
                            x-on:click.stop="$store.selection.toggle({{ $row['id'] }})"
                        />
                    </div>

                    {{-- Type glyph: single-weight monochrome, never coloured (design plan §1). --}}
                    <div class="flex w-5 shrink-0 items-center justify-center text-ink">
                        @switch ($row['type_icon'])
                            @case ('photo')
                                <flux:icon.photo variant="micro" />
                                @break
                            @case ('table-cells')
                                <flux:icon.table-cells variant="micro" />
                                @break
                            @case ('archive-box')
                                <flux:icon.archive-box variant="micro" />
                                @break
                            @case ('document-text')
                                <flux:icon.document-text variant="micro" />
                                @break
                            @default
                                <flux:icon.document variant="micro" />
                        @endswitch
                    </div>

                    {{-- Name: 13/500, truncates with the extension preserved by the browser's own middle-of-string ellipsis on the stem being unlikely, so end-truncation is close enough here. --}}
                    <div class="min-w-[160px] flex-1 truncate pr-2 text-[13px] leading-5 font-medium text-ink">
                        {{ $row['name'] }}
                    </div>

                    {{--
                        State: nothing at all for a healthy row -- a tick on
                        every row would be noise (design plan §4, §5). Every
                        glyph's own svg carries `aria-hidden` from Flux's icon
                        component, so the accessible name comes from a
                        visually-hidden span next to it, not an attribute
                        that would be ignored (design plan §10).
                    --}}
                    <div class="flex w-24 shrink-0 items-center gap-1 lg:hidden">
                        @if ($row['state_icon_narrow'])
                            @php $icon = $row['state_icon_narrow']; @endphp
                            @php
                                $colorClass = match ($icon['color']) {
                                    'hold' => 'text-hold',
                                    'attention' => 'text-attention',
                                    default => 'text-ink-2',
                                };
                            @endphp
                            <x-dynamic-component :component="'flux::icon.'.$icon['icon']" variant="micro" :class="$colorClass" />
                            <span class="sr-only">{{ $icon['label'] }}</span>
                        @endif
                    </div>
                    <div class="hidden w-24 shrink-0 items-center gap-1 lg:flex">
                        @foreach ($row['state_icons'] as $icon)
                            @php
                                $colorClass = match ($icon['color']) {
                                    'hold' => 'text-hold',
                                    'attention' => 'text-attention',
                                    default => 'text-ink-2',
                                };
                            @endphp
                            <x-dynamic-component :component="'flux::icon.'.$icon['icon']" variant="micro" :class="$colorClass" />
                            <span class="sr-only">{{ $icon['label'] }}</span>
                        @endforeach
                    </div>

                    {{-- Period: `.num` -- every numeric cell gets tabular figures (design plan §2). --}}
                    <div class="num hidden w-16 shrink-0 text-xs text-ink-2 xl:block">
                        {{ $row['period_label'] }}
                    </div>

                    {{-- Owner: text, so no `.num`. --}}
                    <div class="hidden w-32 shrink-0 truncate text-xs text-ink-2 min-[1440px]:block">
                        {{ $row['owner'] ?? '—' }}
                    </div>

                    {{-- Modified: fixed-width ISO so digits stack; shortens at narrower widths (design plan §4). --}}
                    <div class="w-32 shrink-0 text-xs text-ink-2">
                        <span class="num hidden lg:inline">{{ $row['modified_full'] }}</span>
                        <span class="num hidden min-[900px]:inline lg:hidden">{{ $row['modified_medium'] }}</span>
                        <span class="num inline min-[900px]:hidden">{{ $row['modified_short'] }}</span>
                    </div>

                    {{-- Size: right-aligned, `.num`. --}}
                    <div class="num w-[72px] shrink-0 text-right text-xs text-ink-2">
                        {{ $row['size'] }}
                    </div>

                    {{-- Version: `.num`, right-aligned. --}}
                    <div class="num hidden w-10 shrink-0 text-right text-xs text-ink-2 xl:block">
                        {{ $row['version'] }}
                    </div>

                    {{-- Row menu: on hover and focus; the menu itself is Task 6's (design plan §6). --}}
                    <div class="flex w-7 shrink-0 items-center justify-center">
                        <button
                            type="button"
                            class="opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 focus-visible:opacity-100"
                            aria-label="{{ __('More actions for :name', ['name' => $row['name']]) }}"
                        >
                            <flux:icon.ellipsis-horizontal variant="micro" class="text-ink-2" />
                        </button>
                    </div>
                </div>
            @endif
        @empty
            <div class="p-6 text-sm text-ink-2">{{ __('No files.') }}</div>
        @endforelse
    </div>
</div>
