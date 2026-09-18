@props([
    // The Alpine state key on the ancestor x-data that opens this modal.
    'state',
    'title' => '',
    'test' => null,
])

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
    class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-24"
    @if ($test) data-test="{{ $test }}" @endif
    x-on:keydown.escape.window="{{ $state }} = false"
>
    {{-- The scrim closes on click, which is the behaviour every dialog has;
         it is a sibling rather than a parent so a click inside the panel
         cannot bubble out to it and close the thing being used. --}}
    <div
        class="absolute inset-0 bg-ink/20"
        x-on:click="{{ $state }} = false"
        aria-hidden="true"
    ></div>

    <div
        class="relative w-full max-w-md rounded-lg border border-rule bg-sheet shadow-lg"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $title }}"
        x-trap.noscroll="{{ $state }}"
    >
        <div class="flex items-center justify-between border-b border-rule px-4 py-3">
            <h2 class="text-sm font-semibold text-ink">{{ $title }}</h2>

            <button
                type="button"
                class="text-ink-2 hover:text-ink"
                x-on:click="{{ $state }} = false"
                aria-label="{{ __('Close') }}"
            >
                <flux:icon.x-mark variant="micro" />
            </button>
        </div>

        <div class="px-4 py-4">
            {{ $slot }}
        </div>
    </div>
</div>
