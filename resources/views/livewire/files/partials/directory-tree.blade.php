{{--
    Recursively renders one level of the Files sidebar's reach-root tree
    (see Browser::render()'s 'sidebarTree' key and
    DirectoryAccess::reachTree()). $nodes is always already filtered to
    what the viewer may view -- nothing here decides visibility, it only
    lays out what it was handed (CLAUDE.md: filter in the query, never in
    the view).

    Every x-on/x-show/x-text below resolves against the Alpine component
    declared on the ancestor <aside data-test="directory-tree"> in
    browser.blade.php -- toggle()/isExpanded() persist per-directory
    collapse state to localStorage, wrapped in try/catch there because it
    throws in a private window. Nothing in this partial needs its own
    x-data.
--}}
@foreach ($nodes as $node)
    <li data-test="sidebar-directory" data-directory-id="{{ $node->id }}" data-drop-directory-id="{{ $node->id }}">
        <div class="group flex h-7 items-center gap-1 rounded px-1 hover:bg-sheet">
            @if ($node->children->isNotEmpty())
                {{-- A plain <button>, not flux:button: this is a dense tree
                     row and the built-in button's default padding would
                     throw the row's alignment off. data-test stays on a
                     plain element regardless (CLAUDE.md).

                     The chevron rotates through a CSS transition rather than
                     move(): 120ms of rotation needs neither a spring nor
                     JavaScript. It is reduced-motion-safe because app.css
                     collapses every transition under the media query -- the
                     per-element `motion-reduce:` variant this used to need
                     could be forgotten, and was. --}}
                <button
                    type="button"
                    data-test="sidebar-toggle"
                    class="flex w-4 shrink-0 cursor-pointer items-center justify-center text-ink-2 hover:text-ink"
                    x-on:click="toggle({{ $node->id }})"
                    :aria-expanded="isExpanded({{ $node->id }}) ? 'true' : 'false'"
                    aria-label="{{ __('Toggle :name', ['name' => $node->name]) }}"
                >
                    <flux:icon.chevron-right
                        variant="micro"
                        class="transition-transform duration-[120ms] ease-[cubic-bezier(0.2,0,0,1)]"
                        x-bind:class="isExpanded({{ $node->id }}) ? 'rotate-90' : ''"
                    />
                </button>
            @else
                <span class="w-4 shrink-0"></span>
            @endif

            <flux:icon.folder variant="micro" class="shrink-0 text-ink-2" />

            <flux:link
                :href="route('files.browse', $node)"
                wire:navigate
                variant="ghost"
                class=" min-w-0 flex-1 truncate text-sm text-ink"
            >{{ $node->name }}</flux:link>
        </div>

        @if ($node->children->isNotEmpty())
            <ul class="ml-4 space-y-0.5" x-show="isExpanded({{ $node->id }})">
                @include('livewire.files.partials.directory-tree', ['nodes' => $node->children])
            </ul>
        @endif
    </li>
@endforeach
