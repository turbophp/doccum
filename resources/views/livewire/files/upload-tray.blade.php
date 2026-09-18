{{--
    Drag-and-drop's server-facing half (design plan §6, §8): the desktop-file
    dropzone overlay, the reason pill a refused drop states rather than doing
    nothing, and the upload tray itself.

    This component renders wherever it is mounted; it does not lay out the
    list pane (FileList's view owns that, Task 4). Everything below is
    `position: fixed`, sized against `[data-file-grid]` at runtime by dnd.js,
    so where this partial physically sits in the DOM does not matter.

    `data-upload-tray` is the one hook dnd.js needs to find this component: it
    reads `wire:id` off this same root element (Livewire always writes one)
    to call `$wire.uploadMultiple(...)` on the right instance, and reads
    `data-period-archived` / `data-can-upload` to word the desktop overlay
    without guessing at server state.
--}}
<div
    data-upload-tray
    data-directory-id="{{ $directory->id }}"
    data-directory-name="{{ $directory->name }}"
    data-period-archived="{{ $periodArchived ? 'true' : 'false' }}"
    data-can-upload="{{ $canUploadHere ? 'true' : 'false' }}"
    x-data="{
        overlay: false,
        reason: null,
        items: [],
        progress(detail) {
            const existing = this.items.find((item) => item.key === detail.key);

            if (existing) {
                existing.percent = detail.percent;
            } else {
                this.items.push({ key: detail.key, name: detail.name, percent: detail.percent, status: 'uploading' });
            }
        },
    }"
    x-on:shell-drag-overlay.window="overlay = $event.detail.show; reason = $event.detail.reason ?? null"
    x-on:shell-upload-progress.window="progress($event.detail)"
    x-on:shell-upload-settled.window="
        const item = items.find((i) => i.key === $event.detail.key);
        if (item) { item.status = $event.detail.status; item.percent = 100; }
    "
>
    {{--
        Files-from-the-desktop dropzone (design plan §6): a solid 2px inset
        ring in `select`, the pane dimmed under a `sheet` overlay, a sentence
        naming where the files land -- never dashed. dnd.js sizes and
        positions this to cover `[data-file-grid]` while a desktop drag is
        over it; it is inert (`pointer-events-none`) so it never steals the
        drop it is only describing.
    --}}
    <div
        x-show="overlay"
        x-cloak
        style="display: none;"
        class="pointer-events-none fixed z-40 flex flex-col items-center justify-center gap-1 text-center"
        data-drop-overlay
    >
        <p class="text-sm font-medium text-ink" x-text="reason ?? ('{{ __('Drop to upload into') }}' + ' ' + '{{ $directory->name }}')"></p>
        <p class="text-xs text-ink-2" x-show="!reason">{{ __('Or drop on a folder to upload there') }}</p>
    </div>

    {{--
        The reason a row-onto-folder or row-onto-tree-node drag was refused
        (design plan §6, "Accept": "Invalid target: nothing changes, cursor
        not-allowed, status bar states the reason"). This is the sentence
        living in a file this task owns rather than depending on the status
        bar (Task 8) existing yet -- a records system states a refusal
        wherever it happens, not only where a later task remembers to read
        it.
    --}}
    <div
        x-show="reason && !overlay"
        x-cloak
        style="display: none;"
        class="pointer-events-none fixed z-40 rounded border border-rule bg-sheet px-2 py-1 text-xs text-ink-2 shadow-sm"
        data-drag-reason
        x-text="reason"
    ></div>

    {{--
        The tray itself (design plan §8): 320px, bottom right of the list
        pane, in-flight items with real-bytes progress (motion 11) in `ink`,
        never `select` -- blue on a row reads as selection, and this is not a
        row. Collapses to nothing once every listed item is empty.
    --}}
    <div
        x-show="items.length > 0"
        x-cloak
        style="display: none;"
        class="fixed right-4 bottom-10 z-30 w-80 rounded border border-rule bg-sheet shadow-lg"
        data-upload-tray-list
    >
        <template x-for="item in items" :key="item.key">
            <div class="border-b border-rule px-3 py-2 text-xs last:border-b-0">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate text-ink" x-text="item.name"></span>
                    <span
                        class="shrink-0 text-ink-2"
                        x-text="item.status === 'failed' ? (item.reason ?? '{{ __('Failed') }}') : (item.status === 'done' ? '{{ __('Done') }}' : Math.round(item.percent ?? 0) + '%')"
                        x-bind:class="item.status === 'failed' ? 'text-attention' : ''"
                    ></span>
                </div>

                {{--
                    2px bar in `ink`, width tied to real bytes reported by
                    Livewire's own upload progress callback -- never an
                    indeterminate animation (design plan §5, motion 11).
                --}}
                <div class="mt-1 h-0.5 w-full bg-rule" x-show="item.status === 'uploading'">
                    <div class="h-0.5 bg-ink" x-bind:style="`width: ${item.percent ?? 0}%`"></div>
                </div>
            </div>
        </template>
    </div>

    @foreach ($items as $item)
        {{-- Server-recorded outcomes (design plan §8): real, not simulated -- the client-side list above tracks bytes in flight, this is what actually happened once the round trip finished. --}}
        <span class="sr-only" data-upload-item data-status="{{ $item['status'] }}">{{ $item['name'] }}{{ $item['reason'] ? ': '.$item['reason'] : '' }}</span>
    @endforeach
</div>
