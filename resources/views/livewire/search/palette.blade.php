<div
    x-data="{
        selected: 0,
        move(step, count) {
            if (count === 0) return;
            this.selected = (this.selected + step + count) % count;
            this.$nextTick(() => {
                this.$refs.list?.querySelector('[data-selected=\'true\']')?.scrollIntoView({ block: 'nearest' });
            });
        },
        openSelected() {
            this.$refs.list?.querySelector('[data-selected=\'true\']')?.click();
        },
    }"
    x-on:keydown.window.prevent.cmd.k="$wire.openPalette()"
    x-on:keydown.window.prevent.ctrl.k="$wire.openPalette()"
    x-on:open-search-palette.window="$wire.openPalette()"
    data-test="search-palette"
>
    @if ($open)
        {{-- Rendered only while open, so the page carries no hidden dialog and
             no listeners over a listing that is not using them. --}}
        <div class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[12vh]" x-on:keydown.escape.window="$wire.closePalette()">
            <div class="absolute inset-0 bg-ink/20" x-on:click="$wire.closePalette()" aria-hidden="true"></div>

            <div
                class="relative flex max-h-[70vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-rule bg-sheet shadow-lg"
                role="dialog"
                aria-modal="true"
                aria-label="{{ __('Search documents') }}"
                x-trap.noscroll="true"
            >
                <div class="flex shrink-0 items-center gap-2 border-b border-rule px-4">
                    <flux:icon.magnifying-glass variant="micro" class="shrink-0 text-ink-2" aria-hidden="true" />

                    {{-- aria-label, not a visible label: the palette IS the
                         search box, so a caption above it would only repeat
                         the placeholder. Named "Find documents" rather than
                         "Search" because the results page's own field is
                         "Search" exactly, and two controls answering to one
                         exact name make every locator that uses it
                         ambiguous. --}}
                    <input
                        type="text"
                        wire:model.live.debounce.200ms="query"
                        x-on:keydown.down.prevent="move(1, {{ $hits->count() }})"
                        x-on:keydown.up.prevent="move(-1, {{ $hits->count() }})"
                        x-init="$nextTick(() => $el.focus())"
                        aria-label="{{ __('Find documents') }}"
                        placeholder="{{ __('Search names, properties and the text inside documents') }}"
                        autocomplete="off"
                        class="h-12 w-full border-0 bg-transparent text-sm text-ink placeholder:text-ink-2 focus:outline-none focus:ring-0"
                        data-test="palette-input"
                    />

                    <kbd class="shrink-0 rounded border border-rule bg-chrome px-1.5 py-0.5 font-sans text-[11px] text-ink-2">esc</kbd>
                </div>

                <div class="min-h-0 flex-1 overflow-auto" x-ref="list" data-test="palette-results">
                    @forelse ($hits as $index => $hit)
                        {{-- Block form, never the single-expression one: Blade
                             lifts raw PHP blocks out before it strips anything,
                             pairing each opener with the next closer, so a
                             single-expression opener here would pair with the
                             block further down and swallow the markup between
                             them. --}}
                        @php
                            $href = $this->destinationFor($hit);
                        @endphp

                        @if ($href)
                            <a
                                href="{{ $href }}"
                                wire:navigate
                                x-on:click="$wire.closePalette()"
                                x-bind:data-selected="selected === {{ $index }} ? 'true' : 'false'"
                                x-on:mouseenter="selected = {{ $index }}"
                                class="flex items-start gap-2.5 px-4 py-2.5 text-sm no-underline data-[selected=true]:bg-chrome"
                                data-test="palette-hit"
                            >
                                <flux:icon
                                    :icon="$hit->subjectType === 'file' ? 'document' : 'folder'"
                                    variant="micro"
                                    class="mt-0.5 size-4 shrink-0 text-ink-2"
                                    aria-hidden="true"
                                />

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-ink">{{ $hit->title }}</span>

                                    @if ($hit->snippet !== '')
                                        {{-- Escaped first, then the index's two
                                             private-use markers become <mark>;
                                             see the results page for why that
                                             order is what makes document text
                                             safe to show. --}}
                                        @php
                                            $snippet = str_replace(
                                                [e(\App\Search\Fts5SearchIndex::MARK_OPEN), e(\App\Search\Fts5SearchIndex::MARK_CLOSE)],
                                                ['<mark class="rounded-sm bg-attention/20 px-0.5 text-ink">', '</mark>'],
                                                e(Str::limit($hit->snippet, 160)),
                                            );
                                        @endphp

                                        <span class="mt-0.5 block truncate text-xs text-ink-2">{!! $snippet !!}</span>
                                    @endif
                                </span>
                            </a>
                        @endif
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-ink-2" data-test="palette-empty">
                            @if (mb_strlen(trim($query)) < 2)
                                {{ __('Type to search across every document you can reach.') }}
                            @else
                                {{ __('Nothing matched :query.', ['query' => trim($query)]) }}
                            @endif
                        </p>
                    @endforelse
                </div>

                @if ($hits->isNotEmpty())
                    <div class="flex shrink-0 items-center justify-between border-t border-rule px-4 py-2 text-xs text-ink-2">
                        <span>{{ trans_choice(':count result|:count results', $hits->count(), ['count' => $hits->count()]) }}</span>
                        <a href="{{ route('search', ['q' => trim($query)]) }}" wire:navigate class="no-underline hover:text-ink">{{ __('All results and filters') }}</a>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
