<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Set up doccum')" :description="__('Configure this instance, then create its first administrator')" />

    <div class="flex items-center justify-center gap-2">
        <flux:badge :color="$step === 1 ? 'blue' : 'zinc'" size="sm">1. {{ __('Database') }}</flux:badge>
        <flux:badge :color="$step === 2 ? 'blue' : 'zinc'" size="sm">2. {{ __('Storage') }}</flux:badge>
        <flux:badge :color="$step === 3 ? 'blue' : 'zinc'" size="sm">3. {{ __('Administrator') }}</flux:badge>
    </div>

    @if ($step === 1)
        <!-- Step 1: Database -->
        <form wire:submit="saveDatabase" class="flex flex-col gap-6">
            <flux:text class="text-sm">
                {{ __('The embedded database needs no configuration. Choose a server type to use your own.') }}
            </flux:text>

            <flux:select wire:model.live="db_connection" :label="__('Database type')">
                <flux:select.option value="sqlite">{{ __('Embedded (SQLite) — no server needed') }}</flux:select.option>
                <flux:select.option value="mysql">{{ __('MySQL') }}</flux:select.option>
                <flux:select.option value="mariadb">{{ __('MariaDB') }}</flux:select.option>
                <flux:select.option value="pgsql">{{ __('PostgreSQL') }}</flux:select.option>
            </flux:select>

            @if ($db_connection === 'sqlite')
                <flux:input
                    wire:model="db_database"
                    :label="__('Database file path')"
                    type="text"
                    placeholder="/data/doccum.sqlite"
                />
            @else
                <flux:input wire:model="db_host" :label="__('Host')" type="text" placeholder="db" />

                <flux:input wire:model="db_port" :label="__('Port')" type="text" placeholder="5432" />

                <flux:input wire:model="db_database" :label="__('Database name')" type="text" placeholder="doccum" />

                <flux:input wire:model="db_username" :label="__('Username')" type="text" placeholder="doccum" />

                <flux:input
                    wire:model="db_password"
                    :label="__('Password')"
                    type="password"
                    viewable
                />
            @endif

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="setup-database-button">
                    {{ __('Test connection & continue') }}
                </flux:button>
            </div>
        </form>
    @elseif ($step === 2)
        <!-- Step 2: Object storage -->
        <form wire:submit="saveStorage" class="flex flex-col gap-6">
            <flux:text>
                {{ __('Embedded storage works with no configuration. Pick a provider below to use your own bucket instead.') }}
            </flux:text>

            <flux:select wire:model.live="storage_provider" :label="__('Storage provider')">
                <flux:select.option value="embedded">{{ __('Embedded (bundled MinIO)') }}</flux:select.option>
                <flux:select.option value="s3">{{ __('Amazon S3') }}</flux:select.option>
                <flux:select.option value="r2">{{ __('Cloudflare R2') }}</flux:select.option>
                <flux:select.option value="spaces">{{ __('DigitalOcean Spaces') }}</flux:select.option>
                <flux:select.option value="wasabi">{{ __('Wasabi') }}</flux:select.option>
                <flux:select.option value="backblaze_b2">{{ __('Backblaze B2') }}</flux:select.option>
                <flux:select.option value="custom">{{ __('Custom S3-compatible endpoint') }}</flux:select.option>
            </flux:select>

            @if ($storage_provider !== 'embedded')
                @if ($storage_provider === 'r2')
                    <flux:input
                        wire:model="s3_account"
                        :label="__('Account ID')"
                        type="text"
                        placeholder="abc123"
                        :description="__('The endpoint is derived from this automatically.')"
                    />
                @endif

                @if ($storage_provider === 'custom')
                    <flux:input wire:model="s3_endpoint" :label="__('Endpoint')" type="text" placeholder="https://s3.example.com" />
                @endif

                @if (in_array($storage_provider, ['s3', 'spaces', 'wasabi', 'backblaze_b2'], true))
                    <flux:input wire:model="s3_region" :label="__('Region')" type="text" placeholder="us-east-1" />
                @endif

                <flux:input wire:model="s3_bucket" :label="__('Bucket')" type="text" placeholder="doccum" />

                <flux:input wire:model="s3_key" :label="__('Access key')" type="text" />

                <flux:input
                    wire:model="s3_secret"
                    :label="__('Secret key')"
                    type="password"
                    viewable
                />
            @endif

            <div class="flex items-center justify-between gap-2">
                <flux:button wire:click="skipStorage" variant="ghost" data-test="setup-storage-skip-button">
                    {{ __('Use default storage') }}
                </flux:button>

                <flux:button type="submit" variant="primary" data-test="setup-storage-button">
                    {{ $storage_provider === 'embedded' ? __('Continue') : __('Test connection & continue') }}
                </flux:button>
            </div>
        </form>
    @else
        <!-- Step 3: Administrator -->
        <form wire:submit="submit" class="flex flex-col gap-6">
            <!-- Instance name -->
            <flux:input
                wire:model="instance_name"
                :label="__('Instance name')"
                type="text"
                required
                autofocus
                :placeholder="__('doccum')"
            />

            <!-- Name -->
            <flux:input
                wire:model="name"
                :label="__('Name')"
                type="text"
                required
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Username -->
            <flux:input
                wire:model="username"
                :label="__('Username')"
                type="text"
                required
                autocomplete="username"
                :placeholder="__('username')"
                :description="__('Lowercase letters, numbers, dots, dashes and underscores. Names your personal folder.')"
            />

            <!-- Email Address -->
            <flux:input
                wire:model="email"
                :label="__('Email address')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="setup-submit-button">
                    {{ __('Create administrator account') }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
