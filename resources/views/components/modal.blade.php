@props([
    // The Alpine state key on the ancestor x-data that opens this modal.
    'state',
    'title' => '',
    'test' => null,
    // 'md' for a form; 'full' for content that needs the room -- a preview
    // of a scanned page is unreadable in a 28rem box.
    'size' => 'md',
    // Optional controls rendered in the header, to the left of the close
    // button: Download on a preview, and anything else that acts on what is
    // being shown rather than on the dialog itself.
    'controls' => null,
    // What closing runs. Defaults to flipping the Alpine state; a dialog whose
    // open/closed state lives on the SERVER (the preview, keyed by
    // previewFileId) passes a $wire call instead, so Escape and the scrim do
    // the same thing the close button does.
    'dismiss' => null,
])

@php($dismissExpression = $dismiss ?? ($state.' = false'))

{{--
    A hand-rolled dialog, deliberately not flux:modal.

    Flux's free tier ships stubs for components the built image cannot
    resolve -- flux:table renders perfectly under the test renderer and fails
    inside the container (rule F in browser.blade.php). Rendering locally is
    therefore no evidence at all: flux:table "renders" in tinker on this
    machine right now. A dialog is thirty lines, so it is written out rather
    than gambled on.

    x-show, never x-if: the contents stay in the DOM while closed, so a file
    input inside can still be populated programmatically and Livewire keeps
    its bindings across opens.
--}}
<div
    x-show="{{ $state }}"
    x-cloak
    @class([
        'fixed inset-0 z-50 flex justify-center px-4',
        'items-start pt-24' => $size !== 'full',
        'items-center py-6' => $size === 'full',
    ])
    @if ($test) data-test="{{ $test }}" @endif
    x-on:keydown.escape.window="{{ $dismissExpression }}"
>
    {{-- The scrim closes on click, which is the behaviour every dialog has;
         it is a sibling rather than a parent so a click inside the panel
         cannot bubble out to it and close the thing being used. --}}
    <div
        class="absolute inset-0 bg-ink/20"
        x-on:click="{{ $dismissExpression }}"
        aria-hidden="true"
    ></div>

    <div
        @class([
            'relative flex w-full flex-col rounded-lg border border-rule bg-sheet shadow-lg',
            'max-w-md' => $size !== 'full',
            'h-[88vh] max-w-[min(1400px,94vw)]' => $size === 'full',
        ])
        role="dialog"
        aria-modal="true"
        aria-label="{{ $title }}"
        x-trap.noscroll="{{ $state }}"
    >
        <div class="flex shrink-0 items-center gap-3 border-b border-rule px-4 py-3">
            <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{{ $title }}</h2>

            @if ($controls)
                <div class="flex shrink-0 items-center gap-2">{{ $controls }}</div>
            @endif

            <button
                type="button"
                class="text-ink-2 hover:text-ink"
                x-on:click="{{ $dismissExpression }}"
                aria-label="{{ __('Close') }}"
            >
                <flux:icon.x-mark variant="micro" />
            </button>
        </div>

        <div @class([
            'px-4 py-4',
            'min-h-0 flex-1 overflow-auto' => $size === 'full',
        ])>
            {{ $slot }}
        </div>
    </div>
</div>
