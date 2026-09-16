<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl">{{ __('Search') }}</flux:heading>
        <flux:subheading>
            {{ __('Names, properties and the text inside documents.') }}
        </flux:subheading>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <flux:input
            wire:model.live.debounce.300ms="query"
            :label="__('Search')"
            type="search"
            autofocus
            :placeholder="__('Try a word from inside a document')"
            class="flex-1"
        />

        <flux:select wire:model.live="subjectType" :label="__('Type')" class="sm:w-48">
            <flux:select.option value="">{{ __('Everything') }}</flux:select.option>
            <flux:select.option value="file">{{ __('Files') }}</flux:select.option>
            <flux:select.option value="directory">{{ __('Folders') }}</flux:select.option>
            <flux:select.option value="property">{{ __('Properties') }}</flux:select.option>
        </flux:select>
    </div>

    @if (trim($query) === '')
        <flux:text>{{ __('Type something to search.') }}</flux:text>
    @elseif ($hits->isEmpty())
        {{-- Deliberately the same message whether nothing matched or everything
             that matched is out of reach: saying "you may not see this" would
             disclose that it exists. --}}
        <flux:text>{{ __('No results.') }}</flux:text>
    @else
        <flux:text class="text-sm">
            {{ trans_choice('{1} :count result|[2,*] :count results', $hits->count(), ['count' => $hits->count()]) }}
        </flux:text>

        <div class="flex flex-col divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($hits as $hit)
                <div class="flex flex-col gap-1 py-4" wire:key="hit-{{ $hit->subjectType }}-{{ $hit->subjectId }}">
                    <div class="flex items-center gap-2">
                        <flux:badge size="sm" color="zinc">{{ $hit->subjectType }}</flux:badge>

                        @if ($hit->subjectType === 'directory')
                            <flux:link href="{{ route('files.browse', $hit->subjectId) }}" class="font-medium">
                                {{ $hit->title }}
                            </flux:link>
                        @elseif ($hit->directoryId)
                            <flux:link href="{{ route('files.browse', $hit->directoryId) }}" class="font-medium">
                                {{ $hit->title }}
                            </flux:link>
                        @else
                            <flux:text class="font-medium">{{ $hit->title }}</flux:text>
                        @endif

                        @if ($hit->directoryId && isset($directories[$hit->directoryId]))
                            <flux:text class="text-xs">{{ __('in') }} {{ $directories[$hit->directoryId] }}</flux:text>
                        @endif
                    </div>

                    @if ($hit->snippet !== '')
                        <flux:text class="text-sm">{{ Str::limit($hit->snippet, 220) }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
