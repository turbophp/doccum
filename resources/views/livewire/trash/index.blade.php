<section class="w-full space-y-6">
    <div class="flex flex-col gap-2">
        <flux:heading size="xl">{{ __('Trash') }}</flux:heading>
        <flux:subheading>
            {{ __('Restore something that was trashed, or purge it for good. Only items you have access to are listed.') }}
        </flux:subheading>
    </div>

    @if ($error)
        {{-- Plain <div>, not flux:callout -- Flux free tier does not ship
             that component (CLAUDE.md: only flux:input/select/button/link/
             heading/checkbox/text/breadcrumbs exist in the built image). --}}
        <div data-test="trash-error" class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300">
            {{ $error }}
        </div>
    @endif

    <div class="space-y-2" data-test="trashed-directories-list">
        <flux:heading level="2">{{ __('Directories') }}</flux:heading>

        @forelse ($directories as $item)
            <div class="flex items-center gap-4" data-test="trashed-directory-row" wire:key="trashed-dir-{{ $item->id }}">
                <flux:text class="font-medium">{{ $item->name }}</flux:text>

                @can('restore', $item)
                    <flux:button
                        size="sm"
                        wire:click="restoreDirectory({{ $item->id }})"
                        wire:confirm="{{ __('Restore this directory and everything trashed with it?') }}"
                        data-test="restore-directory-button"
                    >
                        {{ __('Restore') }}
                    </flux:button>
                @endcan
            </div>
        @empty
            <flux:text data-test="trashed-directories-empty">{{ __('No trashed directories.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-2" data-test="trashed-files-list">
        <flux:heading level="2">{{ __('Files') }}</flux:heading>

        <table data-test="trashed-files-table" class="w-full text-left text-sm">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Was in') }}</th>
                    <th>{{ __('Owner') }}</th>
                    <th>{{ __('Trashed') }}</th>
                    <th class="w-40"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($files as $item)
                    <tr data-test="trashed-file-row" wire:key="trashed-file-{{ $item->id }}">
                        <td>{{ $item->name }}</td>
                        <td>{{ $directoryNames[$item->directory_id] ?? __('(gone)') }}</td>
                        <td>{{ $item->creator?->name }}</td>
                        <td>{{ $item->deleted_at?->diffForHumans() }}</td>
                        <td>
                            <div class="flex items-center gap-2">
                                @can('restore', $item)
                                    <flux:button
                                        size="sm"
                                        wire:click="restoreFile({{ $item->id }})"
                                        data-test="restore-file-button"
                                    >
                                        {{ __('Restore') }}
                                    </flux:button>
                                @endcan

                                @can('purge', $item)
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        wire:click="purgeFile({{ $item->id }})"
                                        wire:confirm="{{ __('Permanently delete this file? This cannot be undone.') }}"
                                        data-test="purge-file-button"
                                    >
                                        {{ __('Purge') }}
                                    </flux:button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><flux:text data-test="trashed-files-empty">{{ __('No trashed files.') }}</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
