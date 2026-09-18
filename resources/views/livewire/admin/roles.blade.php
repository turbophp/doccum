<section class="w-full space-y-6">
    <flux:heading level="1">{{ __('Roles & permissions') }}</flux:heading>
    <flux:text>{{ __('Choose what each role may do. Changes take effect immediately for everyone who holds that role.') }}</flux:text>

    @error('lastAdministrator')
        {{-- Plain <div>, not flux:callout -- see resources/views/livewire/trash/index.blade.php's
             own note and resources/views/livewire/admin/users.blade.php's identical
             element: the Flux free tier this image ships does not include
             flux:callout, and CLAUDE.md is explicit that a Blade assertion rendered
             with the test renderer cannot see a component Flux fails to resolve in
             the built image. A distinct data-test from users.blade.php's
             last-admin-error, so a check can tell which page's refusal it saw. --}}
        <div data-test="roles-last-admin-error" class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300">
            {{ $message }}
        </div>
    @enderror

    {{-- A plain native <table>, not flux:table -- see resources/views/livewire/trash/index.blade.php's
         own note on why: flux:table does not exist in the free tier and fails
         to resolve in the built image while still rendering fine under the
         test renderer. --}}
    <div class="overflow-x-auto">
        <table data-test="roles-permissions-table" class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="py-2 pr-4 font-medium">{{ __('Role') }}</th>
                    @foreach ($permissions as $permission)
                        <th class="px-2 py-2 text-center font-medium" data-test="permission-column-header" data-permission="{{ $permission }}">
                            {{ $permission }}
                        </th>
                    @endforeach
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    <tr wire:key="role-{{ $role->id }}" data-test="role-row" data-role-id="{{ $role->id }}" data-role-name="{{ $role->name }}" class="border-b border-zinc-100 dark:border-zinc-800">
                        <td class="py-2 pr-4">
                            <flux:heading class="truncate">{{ $role->name }}</flux:heading>
                        </td>
                        @foreach ($permissions as $permission)
                            <td class="px-2 py-2 text-center">
                                {{-- A plain <input type="checkbox">, not flux:checkbox -- this
                                     carries data-test and data-permission attributes the
                                     container smoke drives directly, and Flux is only KNOWN
                                     to forward arbitrary attributes on flux:button (see
                                     resources/views/livewire/files/browser.blade.php's own
                                     note beside its file-row-checkbox). --}}
                                <input
                                    type="checkbox"
                                    wire:model="permissionChoice.{{ $role->id }}.{{ $permission }}"
                                    data-test="role-permission-checkbox"
                                    data-role-id="{{ $role->id }}"
                                    data-permission="{{ $permission }}"
                                    class="accent-select"
                                    aria-label="{{ __(':role - :permission', ['role' => $role->name, 'permission' => $permission]) }}"
                                />
                            </td>
                        @endforeach
                        <td class="py-2 text-right">
                            <flux:button wire:click="saveRole({{ $role->id }})" size="sm" data-test="save-role-permissions-button" data-role-id="{{ $role->id }}">
                                {{ __('Save') }}
                            </flux:button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
