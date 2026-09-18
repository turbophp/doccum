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
    @php
        $isCurrent = isset($directory) && $directory?->getKey() === $node->getKey();
    @endphp

    <li data-test="sidebar-directory" data-directory-id="{{ $node->id }}" data-drop-directory-id="{{ $node->id }}">
        {{-- The row carries the state, not the link inside it, so the whole
             strip reads as one target rather than a word floating in a band.
             The current marker is positioned rather than a border, because a
             border would shift every other row's text by its width. --}}
        <div @class([
            'group relative flex h-7 items-center gap-1 rounded-md pr-1.5',
            'bg-select/10' => $isCurrent,
            'hover:bg-chrome' => ! $isCurrent,
        ])>
            @if ($isCurrent)
                <span class="absolute inset-y-1 left-0 w-0.5 rounded-full bg-select" aria-hidden="true"></span>
            @endif

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
                    class="flex size-5 shrink-0 cursor-pointer items-center justify-center rounded text-ink-2 hover:text-ink"
                    x-on:click="toggle({{ $node->id }})"
                    :aria-expanded="isExpanded({{ $node->id }}) ? 'true' : 'false'"
                    aria-label="{{ __('Toggle :name', ['name' => $node->name]) }}"
                >
                    <flux:icon.chevron-right
                        variant="micro"
                        class="size-3.5 transition-transform duration-[120ms] ease-[cubic-bezier(0.2,0,0,1)]"
                        x-bind:class="isExpanded({{ $node->id }}) ? 'rotate-90' : ''"
                    />
                </button>
            @else
                <span class="size-5 shrink-0"></span>
            @endif

            {{-- The link fills the rest of the row, so clicking anywhere right
                 of the chevron navigates. Its accessible name is the
                 directory's name and nothing else -- the icon is decorative,
                 and the container smoke locates these with
                 getByRole('link', { name: <username> }) in eleven places. --}}
            <flux:link
                :href="route('files.browse', $node)"
                wire:navigate
                variant="ghost"
                @class([
                    'flex min-w-0 flex-1 items-center gap-1.5 text-sm text-ink',
                    'font-medium' => $isCurrent,
                ])
            >
                <flux:icon
                    :icon="$isCurrent ? 'folder-open' : 'folder'"
                    variant="micro"
                    class="shrink-0 text-ink-2"
                    aria-hidden="true"
                />
                <span class="truncate">{{ $node->name }}</span>
            </flux:link>
        </div>

        @if ($node->children->isNotEmpty())
            {{-- An indent guide rather than bare margin: three levels deep, a
                 plain indent is a column of text with nothing tying a child to
                 its parent, and counting pixels is not reading. --}}
            <ul class="ml-[0.65rem] space-y-0.5 border-l border-rule/70 pl-2" x-show="isExpanded({{ $node->id }})">
                @include('livewire.files.partials.directory-tree', ['nodes' => $node->children])
            </ul>
        @endif
    </li>
@endforeach
