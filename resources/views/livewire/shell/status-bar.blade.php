{{--
    The shell's footer strip (design plan §3, §6): the current folder's
    counts, the selection count once anything is selected, and the sentence a
    blocked drag or menu action explains itself with, in that priority order.

    Selection is client-side and instant (design plan §5 motion #1, "Select":
    "no animation"): the two blocks below toggle on the same global
    `selection` Alpine store every row in the file list already reads
    (resources/js/shell/selection.js), so switching from folder counts to
    "N selected" never waits on a Livewire round trip. This view only reads
    that store; it never writes to it.

    Colour (design plan §1): the held count alone may be `hold`. Everything
    else here is `ink-2` on `chrome`, inherited from the footer this
    component is rendered inside (layouts::app.shell).
--}}
<div
    class="flex h-full w-full items-center justify-between gap-2 px-2 text-[11px] leading-4 text-ink-2"
    role="status"
    aria-live="polite"
>
    @if ($blockedReason)
        {{-- A blocked drag or a disabled context-menu item explains itself here rather than in a toast (design plan §6). --}}
        <span data-status-reason>{{ $blockedReason }}</span>
    @else
        <div x-data="{}" class="flex min-w-0 flex-1 items-center gap-3">
            {{-- The current folder's counts: replaced by the selection summary the moment anything is selected. --}}
            <div
                data-folder-counts
                class="flex items-center gap-1 truncate"
                x-show="!($store.selection && $store.selection.anySelected())"
            >
                @if ($counts)
                    <span data-item-count>
                        <span class="num">{{ $counts['items'] }}</span>
                        {{ ' '.\Illuminate\Support\Str::plural('file', $counts['items']) }}
                    </span>

                    @if ($counts['held'] > 0)
                        <span aria-hidden="true">,</span>

                        {{-- Red means legal hold and never danger (design plan §1): the held count is the one numeral in this bar allowed to carry colour. --}}
                        <span data-held-count>
                            <span class="num text-hold">{{ $counts['held'] }}</span>
                            {{ ' '.__('under hold') }}
                        </span>
                    @endif

                    @if ($counts['archived'] > 0)
                        <span aria-hidden="true">,</span>

                        <span data-archived-count>
                            <span class="num">{{ $counts['archived'] }}</span>
                            {{ ' '.__('in archived period') }}
                        </span>
                    @endif
                @endif
            </div>

            {{-- The selection summary: Alpine-only, so it appears the instant a row is clicked. --}}
            <div data-selection-count x-show="$store.selection && $store.selection.anySelected()" x-cloak>
                <span class="num" x-text="$store.selection ? $store.selection.ids.length : 0"></span>
                {{ ' '.__('selected') }}
            </div>
        </div>

        @if ($counts)
            <span
                class="num shrink-0"
                data-folder-size
                x-show="!($store.selection && $store.selection.anySelected())"
            >
                {{ $counts['size'] }}
            </span>
        @endif
    @endif
</div>
