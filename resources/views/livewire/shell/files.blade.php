{{--
    Three panes (design plan §3): tree 240 / list flex (min 480) / detail 336.
    The list never drops below 480px; panes detach in the order detail then
    tree, which is why the detail pane carries the larger hidden breakpoint.
--}}
<div class="flex h-full min-h-0 w-full">
    <aside
        class="hidden w-60 shrink-0 overflow-y-auto border-r border-rule bg-chrome md:block"
        aria-label="{{ __('Folders') }}"
    >
        <livewire:files.tree />
    </aside>

    <main class="flex min-w-0 flex-1 flex-col overflow-hidden bg-sheet">
        @if ($directory)
            <livewire:files.file-list :directory="$directory" :key="'list-'.$directory->id" />
        @else
            {{-- An empty screen is an invitation to act, not a mood. --}}
            <div class="flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                <flux:icon.folder-open variant="outline" class="text-ink-2" />
                <flux:text>{{ __('Choose a folder to see what is in it.') }}</flux:text>
            </div>
        @endif
    </main>

    @if ($subject)
        <aside
            class="hidden w-84 shrink-0 overflow-y-auto border-l border-rule bg-sheet xl:block"
            aria-label="{{ __('Details') }}"
        >
            <livewire:files.detail :subject="$subject" :key="'detail-'.class_basename($subject).'-'.$subject->id" />
        </aside>
    @endif
</div>
