{{--
    The detail pane (design plan §3, §5, §7-8; plan Task 5). One flat pane --
    hairlines between sections, not stacked cards, per §7's explicit rejection
    of the SaaS-card kit -- 336px wide by default, bounded 288-480 for
    whatever resizer eventually drives its width (PaneResizer is a separate,
    not-yet-built component per §8; this pane only declares the bounds it
    must respect).

    Three tabs for a file (Properties / Versions / Text); a directory has
    only Properties, because it has no versions and no extracted text --
    the other two tabs are absent from the DOM entirely, not merely empty.

    The 2px tab underline slides with the `tabUnderline` spring exported by
    `resources/js/shell/motion.js` (stiffness 500, damping 40), imported via
    the Alpine magics `resources/js/app.js` registers on `alpine:init`
    (`$move` / `$springs` / `$micro`, wired into the real Vite bundle by
    "fix: wire the motion helper into the bundle"). Using those magics
    instead of a per-view `<script type="module">` is deliberate: motion.js
    is statically imported *into* app.js's own chunk, not built as an entry
    of its own, so a view-level `Vite::asset('resources/js/shell/motion.js')`
    would never resolve to a real file -- the magics are the one path that
    actually reaches the browser.
--}}
@php
    $isFile = $subject instanceof \App\Models\File;
@endphp

<section
    class="flex h-full w-[336px] min-w-[288px] max-w-[480px] flex-col border-l border-rule bg-sheet text-ink"
    x-data="{
        active: 'properties',
        position(tab) {
            this.$nextTick(() => {
                const underline = this.$refs.tabUnderline;
                const target = this.$refs['tab_' + tab];

                if (! underline || ! target) {
                    return;
                }

                this.$move(underline, {
                    left: `${target.offsetLeft}px`,
                    width: `${target.offsetWidth}px`,
                }, this.$springs.tabUnderline);
            });
        },
        select(tab) {
            if (tab === this.active) {
                return;
            }

            this.active = tab;
            this.position(tab);
        },
    }"
    x-init="position(active)"
>
    @if ($isFile && $subject->legal_hold)
        {{-- Motion #8, Stamp: placing a hold grows this band 0->28px with a
             deliberate overshoot; lifting one fades it over a quiet 120ms
             with no spring at all -- the asymmetry is the point. This pane
             only renders the band's current state; the transition itself is
             driven by whatever toggles legal_hold live (Task 6's Actions),
             since Detail does not own that action. --}}
        <div class="flex h-7 shrink-0 items-center gap-1.5 border-b border-rule bg-chrome px-3 text-xs text-hold">
            <flux:icon.lock-closed variant="mini" class="size-4" />
            <span>{{ __('Under legal hold') }}</span>
        </div>
    @endif

    <header class="shrink-0 border-b border-rule px-4 py-3">
        <h2 class="truncate text-base leading-6 font-semibold text-ink">{{ $subject->name }}</h2>
    </header>

    <div class="relative shrink-0 border-b border-rule" role="tablist">
        <div class="flex">
            <button
                type="button"
                role="tab"
                data-tab="properties"
                x-ref="tab_properties"
                :aria-selected="(active === 'properties').toString()"
                @click="select('properties')"
                :class="active === 'properties' ? 'text-ink' : 'text-ink-2'"
                class="px-3 py-2 text-sm font-medium"
            >
                {{ __('Properties') }}
            </button>

            @if ($isFile)
                <button
                    type="button"
                    role="tab"
                    data-tab="versions"
                    x-ref="tab_versions"
                    :aria-selected="(active === 'versions').toString()"
                    @click="select('versions')"
                    :class="active === 'versions' ? 'text-ink' : 'text-ink-2'"
                    class="px-3 py-2 text-sm font-medium"
                >
                    {{ __('Versions') }}
                </button>

                <button
                    type="button"
                    role="tab"
                    data-tab="text"
                    x-ref="tab_text"
                    :aria-selected="(active === 'text').toString()"
                    @click="select('text')"
                    :class="active === 'text' ? 'text-ink' : 'text-ink-2'"
                    class="px-3 py-2 text-sm font-medium"
                >
                    {{ __('Text') }}
                </button>
            @endif
        </div>

        {{-- No CSS transition here: `$move()` (motion.js's `tabUnderline`
             spring) animates `left`/`width` itself, frame by frame, since
             those properties cannot be WAAPI-accelerated. A CSS transition
             on the same properties would fight it, chasing every
             intermediate frame instead of the final position. --}}
        <span x-ref="tabUnderline" class="absolute bottom-0 left-0 h-0.5 w-0 bg-select"></span>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
        <div x-show="active === 'properties'" data-panel="properties">
            <form wire:submit="save">
                <div class="divide-y divide-rule">
                    @forelse ($definitions as $definition)
                        @php $field = "values.{$definition->key}"; @endphp

                        <div class="px-4 py-3">
                            @if ($definition->data_type === \App\Enums\PropertyDataType::Boolean)
                                <flux:checkbox wire:model="{{ $field }}" :label="$definition->label" />
                            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Select)
                                <flux:select wire:model="{{ $field }}" :label="$definition->label">
                                    <flux:select.option value="">{{ __('-- none --') }}</flux:select.option>
                                    @foreach ($definition->options ?? [] as $option)
                                        <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Text)
                                <flux:textarea wire:model="{{ $field }}" :label="$definition->label" />
                            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Date)
                                <flux:input wire:model="{{ $field }}" :label="$definition->label" type="date" />
                            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Number)
                                <flux:input wire:model="{{ $field }}" :label="$definition->label" type="number" step="any" />
                            @else
                                <flux:input wire:model="{{ $field }}" :label="$definition->label" type="text" />
                            @endif
                        </div>
                    @empty
                        <p class="px-4 py-3 text-sm text-ink-2">{{ __('No properties apply here.') }}</p>
                    @endforelse
                </div>

                @if ($definitions->isNotEmpty())
                    <div class="border-t border-rule px-4 py-3">
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    </div>
                @endif
            </form>
        </div>

        @if ($isFile)
            <div x-show="active === 'versions'" x-cloak data-panel="versions">
                @forelse ($versions as $version)
                    <div
                        class="flex items-center gap-3 border-b border-rule px-4 py-2 text-sm"
                        data-version="{{ $version->version_number }}"
                        data-current="{{ $version->id === $currentVersionId ? 'true' : 'false' }}"
                    >
                        <span class="num w-8 shrink-0 text-ink-2">v{{ $version->version_number }}</span>

                        @if ($version->id === $currentVersionId)
                            <span class="shrink-0 text-xs text-ink-2">{{ __('Current') }}</span>
                        @endif

                        <span class="num flex-1 text-ink-2">{{ optional($version->created_at)->format('Y-m-d H:i') }}</span>
                        <span class="num text-ink-2">{{ \Illuminate\Support\Number::fileSize($version->size, precision: 1) }}</span>

                        <a
                            href="{{ route('files.download', $subject) }}"
                            class="flex shrink-0 items-center gap-1 text-ink-2 hover:text-ink"
                        >
                            <flux:icon.arrow-down-tray variant="mini" class="size-4" />
                            {{ __('Download') }}
                        </a>
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-ink-2">{{ __('No versions yet.') }}</p>
                @endforelse
            </div>

            <div x-show="active === 'text'" x-cloak data-panel="text">
                @php $status = $fileText?->status ?? \App\Enums\ExtractionStatus::Pending; @endphp

                <div class="px-4 py-3">
                    @if ($status === \App\Enums\ExtractionStatus::Done)
                        <pre class="max-w-[72ch] font-sans text-sm leading-5 whitespace-pre-wrap text-ink">{{ $fileText->text }}</pre>
                    @elseif ($status === \App\Enums\ExtractionStatus::Pending)
                        <p class="flex items-center gap-1.5 text-sm text-ink-2">
                            <flux:icon.clock variant="mini" class="size-4" />
                            {{ __('Text extraction is pending.') }}
                        </p>
                    @elseif ($status === \App\Enums\ExtractionStatus::Processing)
                        <p class="flex items-center gap-1.5 text-sm text-ink-2">
                            <flux:icon.clock variant="mini" class="size-4" />
                            {{ __('Extracting text…') }}
                        </p>
                    @elseif ($status === \App\Enums\ExtractionStatus::Failed)
                        <p class="flex items-center gap-1.5 text-sm text-attention">
                            <flux:icon.exclamation-triangle variant="mini" class="size-4" />
                            {{ __('Extraction failed: :reason', ['reason' => $fileText->error ?? __('unknown reason')]) }}
                        </p>
                    @else
                        <p class="text-sm text-ink-2">{{ __('This file format cannot be read for text.') }}</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>
