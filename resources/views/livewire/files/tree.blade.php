{{--
    The tree pane (design plan §3, §7, §8). One file, included into itself
    recursively: when `$directory` is set this renders a single node (and,
    if it is expanded, recurses into its own children); otherwise it renders
    the whole pane -- the pinned home directory above the "Shared" section
    (spec §10) -- and only reaches the node branch through that recursion.

    No icons except chevrons (§7, the tree is kept plain to pay for the
    period spine elsewhere): a folder here is a chevron and a name, nothing
    else. Colour: none. Every surface below is `chrome`/`sheet`/`rule` and
    every glyph or label is `ink`/`ink-2` -- the neutral ramp only, never
    `select`, per this task's brief.

    Chevron rotation mirrors motion.js's `micro.chevron` exactly (120ms,
    cubic-bezier(0.2, 0, 0, 1)) as a CSS transition rather than a live
    `move()` call: `resources/js/shell/motion.js` is not yet reachable from
    any Vite entry point (`resources/js/app.js` is still empty, and wiring
    it up is `vite.config.js`/`app.js` territory this task does not own), so
    an ES import here would 404 in both dev and the built container. The
    numbers below are the single source of truth's numbers, kept in sync by
    hand until that wiring lands; swapping this for a real `move()` call
    should not need to touch anything else.
--}}
@php
    $directory ??= null;
    $depth ??= 0;
@endphp

@if ($directory)
    @php
        $children = $this->childrenOf($directory->id);
        $isExpanded = (bool) ($expanded[$directory->id] ?? false);
        $isSelected = $selectedId === $directory->id;
    @endphp

    <div
        role="treeitem"
        aria-expanded="{{ $children->isNotEmpty() ? ($isExpanded ? 'true' : 'false') : 'false' }}"
        aria-selected="{{ $isSelected ? 'true' : 'false' }}"
        wire:key="tree-node-{{ $directory->id }}"
    >
        <div
            class="flex items-center gap-1 rounded px-1 py-1 text-[13px] leading-5 {{ $isSelected ? 'bg-sheet text-ink font-medium' : 'text-ink' }}"
            style="padding-left: {{ 8 + $depth * 16 }}px"
        >
            @if ($children->isNotEmpty())
                <button
                    type="button"
                    wire:click.stop="toggle({{ $directory->id }})"
                    aria-label="{{ $isExpanded ? __('Collapse :name', ['name' => $directory->name]) : __('Expand :name', ['name' => $directory->name]) }}"
                    class="flex size-5 shrink-0 items-center justify-center text-ink-2 hover:text-ink"
                >
                    <flux:icon.chevron-right
                        variant="mini"
                        class="shrink-0 transition-transform duration-[120ms] ease-[cubic-bezier(0.2,0,0,1)] {{ $isExpanded ? 'rotate-90' : '' }}"
                    />
                </button>
            @else
                <span class="inline-block size-5 shrink-0" aria-hidden="true"></span>
            @endif

            <button
                type="button"
                wire:click="select({{ $directory->id }})"
                class="min-w-0 flex-1 truncate text-left"
                title="{{ $directory->name }}"
            >
                {{ $directory->name }}
            </button>
        </div>

        @if ($isExpanded)
            <div role="group">
                @foreach ($children as $child)
                    @include('livewire.files.tree', ['directory' => $child, 'depth' => $depth + 1])
                @endforeach
            </div>
        @endif
    </div>
@else
    <div role="tree" aria-label="{{ __('Directories') }}" class="h-full overflow-y-auto bg-chrome py-2 text-ink">
        @if ($home)
            <div class="mb-2 border-b border-rule pb-2">
                @include('livewire.files.tree', ['directory' => $home, 'depth' => 0])
            </div>
        @endif

        <div>
            <div class="flex items-center gap-1 px-1 py-1 text-[12px] leading-4 text-ink-2" style="padding-left: 8px">
                <button
                    type="button"
                    wire:click="toggleShared"
                    aria-label="{{ $sharedExpanded ? __('Collapse Shared') : __('Expand Shared') }}"
                    class="flex size-5 shrink-0 items-center justify-center text-ink-2 hover:text-ink"
                >
                    <flux:icon.chevron-right
                        variant="mini"
                        class="shrink-0 transition-transform duration-[120ms] ease-[cubic-bezier(0.2,0,0,1)] {{ $sharedExpanded ? 'rotate-90' : '' }}"
                    />
                </button>

                <span>{{ __('Shared') }}</span>
            </div>

            @if ($sharedExpanded)
                <div role="group">
                    @forelse ($roots as $root)
                        @include('livewire.files.tree', ['directory' => $root, 'depth' => 1])
                    @empty
                        <p class="px-2 py-1 text-[12px] leading-4 text-ink-2" style="padding-left: 33px">
                            {{ __('Nothing shared with you yet.') }}
                        </p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
@endif
