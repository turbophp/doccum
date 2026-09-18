<section class="w-full space-y-8">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl">{{ __('Home') }}</flux:heading>
        <flux:subheading>
            {{ __('Recent files and a quick way into search. Only what you may already reach is shown.') }}
        </flux:subheading>
    </div>

    {{-- Quick search entry (spec §10): a plain GET form straight to the
         Search destination, not a Livewire property owned by this
         component. Search\Results already owns #[Url(as: 'q')] and resolves
         the query itself the moment it mounts, so a round trip through THIS
         component before the viewer even gets there would be pure overhead
         for no gain. --}}
    <div data-test="home-quick-search">
        <form method="GET" action="{{ route('search') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:input
                type="search"
                name="q"
                :label="__('Search')"
                :placeholder="__('Try a word from inside a document')"
                class="flex-1"
            />

            <flux:button type="submit">{{ __('Search') }}</flux:button>
        </form>
    </div>

    <div class="space-y-2" data-test="home-recent-files">
        <flux:heading level="2">{{ __('Recent files') }}</flux:heading>

        {{-- MUTATION: the component still resolves $recentFiles exactly as
             before -- the query, the DirectoryAccess filter and the
             SoftDeletes scope are all untouched -- and the view simply
             stops rendering them. Home keeps returning 200 and keeps
             showing its heading, its quick search and its empty state. --}}
        @forelse ([] as $item)
            <div class="flex items-center gap-4" data-test="home-recent-file-row" wire:key="home-recent-file-{{ $item->id }}">
                <flux:link :href="route('files.browse', $item->directory)" wire:navigate class="font-medium">
                    {{ $item->name }}
                </flux:link>

                <flux:text class="text-sm">{{ $item->directory?->name }}</flux:text>
                <flux:text class="text-xs">{{ $item->updated_at?->diffForHumans() }}</flux:text>
            </div>
        @empty
            {{-- The doneWhen's "zero data" clause: a brand-new user, or
                 anyone with no reach at all, gets this rather than a crash
                 or a blank section. --}}
            <flux:text data-test="home-recent-files-empty">{{ __('No recent files yet.') }}</flux:text>
        @endforelse
    </div>
</section>
