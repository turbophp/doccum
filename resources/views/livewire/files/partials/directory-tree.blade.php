{{--
    Recursively renders one level of the Files sidebar's reach-root tree
    (see Browser::render()'s 'sidebarTree' key and
    DirectoryAccess::reachTree()). $nodes is always already filtered to
    what the viewer may view -- nothing here decides visibility, it only
    lays out what it was handed (CLAUDE.md: filter in the query, never in
    the view).

    Every x-on/x-show below resolves against the Alpine component declared
    on the ancestor <aside data-test="directory-tree"> in
    browser.blade.php -- toggle()/isExpanded() persist per-directory
    collapse state to localStorage, wrapped in try/catch there because it
    throws in a private window. Nothing in this partial needs its own
    x-data.

    Plain <a>, not flux:link: flux:link renders `inline` and an accent text
    colour of its own. Layout utilities do not win by being written later in
    an attribute -- `inline` and `flex` have the same specificity, so the
    stylesheet's order decides, and the row's icon and label stopped being a
    flex row at all. The Home entry above already writes its anchor by hand,
    for a different reason; this one does it for this one.
--}}
@foreach ($nodes as $node)
    @php
        $isCurrent = isset($directory) && $directory?->getKey() === $node->getKey();
    @endphp

    <li data-test="sidebar-directory" data-directory-id="{{ $node->id }}" data-drop-directory-id="{{ $node->id }}">
        {{-- Where you are is said with weight and ink, not with a coloured
             panel: a tree is a list of names, and tinting one of them turns a
             quiet index into a component with a state. --}}
        <div @class([
            'flex h-7 items-center gap-1 rounded',
            'bg-chrome' => $isCurrent,
            'hover:bg-chrome/60' => ! $isCurrent,
        ])>
            @if ($node->children->isNotEmpty())
                {{-- A plain <button>, not flux:button: this is a dense tree
                     row and the built-in button's default padding would
                     throw the row's alignment off. data-test stays on a
                     plain element regardless (CLAUDE.md).

                     The chevron rotates through a CSS transition rather than
                     move(): 120ms of rotation needs neither a spring nor
                     JavaScript. It is reduced-motion-safe because app.css
                     collapses every transition under the media query. --}}
                <button
                    type="button"
                    data-test="sidebar-toggle"
                    class="flex size-5 shrink-0 cursor-pointer items-center justify-center text-ink-2 hover:text-ink"
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
            <a
                href="{{ route('files.browse', $node) }}"
                wire:navigate
                @class([
                    'flex min-w-0 flex-1 items-center gap-1.5 pr-1.5 text-sm no-underline',
                    'font-medium text-ink' => $isCurrent,
                    'text-ink-2 hover:text-ink' => ! $isCurrent,
                ])
            >
                <flux:icon.folder variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ $node->name }}</span>
            </a>
        </div>

        @if ($node->children->isNotEmpty())
            {{-- An indent guide rather than bare margin: three levels deep, a
                 plain indent is a column of text with nothing tying a child to
                 its parent, and counting pixels is not reading. --}}
            <ul class="ml-2.5 space-y-px border-l border-rule pl-2" x-show="isExpanded({{ $node->id }})">
                @include('livewire.files.partials.directory-tree', ['nodes' => $node->children])
            </ul>
        @endif
    </li>
@endforeach
