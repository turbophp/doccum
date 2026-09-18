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
    <li data-test="sidebar-directory" data-directory-id="{{ $node->id }}">
        <div class="flex items-center gap-1">
            @if ($node->children->isNotEmpty())
                {{-- A plain <button>, not flux:button: this is a dense tree
                     row and the built-in button's default padding would
                     throw the row's alignment off. data-test stays on a
                     plain element regardless (CLAUDE.md). --}}
                <button
                    type="button"
                    data-test="sidebar-toggle"
                    class="w-4 shrink-0 cursor-pointer text-xs text-zinc-500"
                    x-on:click="toggle({{ $node->id }})"
                    x-text="isExpanded({{ $node->id }}) ? '▾' : '▸'"
                ></button>
            @else
                <span class="w-4 shrink-0"></span>
            @endif

            <flux:link :href="route('files.browse', $node)" wire:navigate>{{ $node->name }}</flux:link>
        </div>

        @if ($node->children->isNotEmpty())
            <ul class="ml-4 space-y-1" x-show="isExpanded({{ $node->id }})">
                @include('livewire.files.partials.directory-tree', ['nodes' => $node->children])
            </ul>
        @endif
    </li>
@endforeach
