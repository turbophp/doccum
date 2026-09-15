<section class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('files.browse')" wire:navigate>{{ __('Files') }}</flux:breadcrumbs.item>
            @if ($directory)
                <flux:breadcrumbs.item>{{ $directory->name }}</flux:breadcrumbs.item>
            @endif
        </flux:breadcrumbs>
    </div>

    <div class="space-y-2">
        <flux:heading level="2">{{ __('Directories') }}</flux:heading>

        @forelse ($directories as $item)
            <div>
                <flux:link :href="route('files.browse', $item)" wire:navigate>{{ $item->name }}</flux:link>
            </div>
        @empty
            <flux:text>{{ __('No subdirectories.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-2">
        <flux:heading level="2">{{ __('Files') }}</flux:heading>

        @forelse ($files as $item)
            <div class="flex items-center gap-4">
                <span>{{ $item->name }}</span>
                <flux:link :href="route('files.download', $item)">{{ __('Download') }}</flux:link>
            </div>
        @empty
            <flux:text>{{ __('No files.') }}</flux:text>
        @endforelse
    </div>

    <form wire:submit="createDirectory" class="flex items-end gap-4">
        <flux:input wire:model="newDirectoryName" :label="__('New folder')" type="text" />
        <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
    </form>

    @if ($directory)
        <form wire:submit="store" class="flex items-end gap-4">
            <flux:input wire:model="upload" :label="__('Upload a file')" type="file" />
            <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
        </form>
    @endif
</section>
