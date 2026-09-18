<section class="w-full space-y-6">
    <flux:heading level="1">{{ __('Archive periods') }}</flux:heading>
    <flux:text>{{ __('Close finished periods to archive them, then purge one once it is old enough and holds nothing under legal hold.') }}</flux:text>

    <form class="max-w-lg space-y-4" data-test="close-period-form">
        <flux:heading level="2">{{ __('Close a period') }}</flux:heading>

        @error('closePeriod')
            {{-- Plain <div>, not flux:callout -- see resources/views/livewire/admin/roles.blade.php's
                 own note: the Flux free tier this image ships does not include
                 flux:callout, and a Blade assertion rendered with the test
                 renderer cannot see a component Flux fails to resolve in the
                 built image. --}}
            <div data-test="close-period-error" class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        {{-- No data-test here, deliberately -- see
             resources/views/livewire/files/browser.blade.php's own note on
             its replace-form: flux:input renders a label/wrapper around the
             real <input>, and there is no precedent in this codebase for an
             attribute placed on flux:input landing on that inner element in
             the built image. getByLabel() locates these instead; only
             flux:button is known to forward arbitrary attributes. --}}
        <flux:input wire:model="closeYear" :label="__('Year')" type="text" inputmode="numeric" />
        <flux:input wire:model="closeMonth" :label="__('Month (optional -- leave blank for the whole year)')" type="text" inputmode="numeric" />

        <flux:button type="submit" variant="primary" data-test="close-period-button">{{ __('Close period') }}</flux:button>
    </form>

    {{-- A plain native <table>, not flux:table -- see resources/views/livewire/admin/roles.blade.php's
         own note: flux:table does not exist in the free tier and fails to
         resolve in the built image while still rendering fine under the test
         renderer. --}}
    <div class="overflow-x-auto">
        <table data-test="periods-table" class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="py-2 pr-4 font-medium">{{ __('Period') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Files') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Bytes') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Blockers') }}</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($periods as $period)
                    @php($plan = $plans[$period->id] ?? null)
                    <tr wire:key="period-{{ $period->id }}" data-test="period-row" data-year="{{ $period->year }}" data-month="{{ $period->month ?? '' }}" class="border-b border-zinc-100 dark:border-zinc-800 align-top">
                        <td class="py-2 pr-4">
                            <flux:heading class="truncate">
                                {{ $period->month === null ? sprintf('%04d', $period->year) : sprintf('%04d-%02d', $period->year, $period->month) }}
                            </flux:heading>
                        </td>
                        <td class="py-2 pr-4" data-test="period-status">
                            @if ($period->isPurged())
                                {{ __('Purged') }}
                            @elseif ($period->isArchived())
                                {{ __('Archived') }}
                            @else
                                {{ __('Open') }}
                            @endif
                        </td>

                        @if ($plan)
                            <td class="py-2 pr-4" data-test="period-file-count">{{ number_format($plan->fileCount) }}</td>
                            <td class="py-2 pr-4" data-test="period-byte-count">{{ number_format($plan->byteCount) }}</td>
                            <td class="py-2 pr-4">
                                @if ($plan->blockers !== [])
                                    <ul class="list-inside list-disc space-y-1" data-test="period-blockers">
                                        @foreach ($plan->blockers as $blocker)
                                            <li data-test="period-blocker">{{ $blocker }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="py-2">
                                <div class="flex flex-col gap-2">
                                    @error('purge.'.$period->id)
                                        <div data-test="purge-error" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300">
                                            {{ $message }}
                                        </div>
                                    @enderror

                                    {{-- No data-test -- see the close-period
                                         form's own note above; nothing in
                                         this codebase drives a flux:input by
                                         one. --}}
                                    <flux:input
                                        wire:model="purgeConfirmation.{{ $period->id }}"
                                        :placeholder="__('Type :label to confirm', ['label' => $plan->label()])"
                                        type="text"
                                    />

                                    <flux:button
                                        wire:click="purgePeriod({{ $period->id }})"
                                        variant="danger"
                                        size="sm"
                                        data-test="purge-period-button"
                                        data-period-id="{{ $period->id }}"
                                        :disabled="! $plan->purgeable"
                                    >
                                        {{ __('Purge') }}
                                    </flux:button>
                                </div>
                            </td>
                        @else
                            <td class="py-2 pr-4" data-test="period-file-count">{{ number_format($period->file_count) }}</td>
                            <td class="py-2 pr-4" data-test="period-byte-count">{{ number_format($period->byte_count) }}</td>
                            <td class="py-2 pr-4"></td>
                            <td class="py-2"></td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-4 text-zinc-500" data-test="periods-empty">{{ __('No periods yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
