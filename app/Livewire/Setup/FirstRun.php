<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Actions\Users\CreateHomeDirectory;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\StorageProvider;
use App\Models\User;
use App\Services\ConnectionProbe;
use App\Services\InstanceState;
use App\Services\Settings;
use App\Support\EmailKey;
use App\Support\RuntimeConfig;
use App\Support\SupervisedProcesses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * The one-time, first-run wizard: database, then storage, then the instance's
 * first admin.
 *
 * Reachable only while no user exists at all (see RequireInstanceSetup), and
 * closed for good the moment one does -- doccum ships no default credentials.
 *
 * Order matters: the database is chosen and migrated BEFORE the admin is
 * created, so the admin lands in the database the operator picked rather than
 * in the bootstrap SQLite. See plan Task 5/7.
 */
#[Title('Set up doccum')]
#[Layout('layouts::auth')]
class FirstRun extends Component
{
    use PasswordValidationRules, ProfileValidationRules;

    /** 1 = database, 2 = storage, 3 = admin. */
    /**
     * The default install asks for nothing but the administrator account:
     * database and object storage are embedded and need no configuration. The
     * stepped database/storage flow is opt-in, for the minority who are
     * pointing doccum at their own infrastructure.
     */
    public bool $advanced = false;

    public int $step = 3;

    /**
     * True once saveDatabase() has recognised the database as an already-
     * populated doccum instance rather than a fresh one. Storage and admin
     * creation must never be reachable once this is true -- see saveDatabase().
     */
    public bool $attaching = false;

    /**
     * Whether the supervised workers picked up the new database. False under
     * compose, where they are separate containers the app cannot restart.
     */
    public bool $workersRestarted = false;

    // -- Step 1: database ------------------------------------------------

    public string $db_connection = 'sqlite';

    public string $db_host = '';

    public string $db_port = '';

    /**
     * Pre-filled with the embedded database's path so the default choice needs
     * no typing at all; replaced by the operator when they pick a server.
     */
    public string $db_database = '/data/doccum.sqlite';

    public string $db_username = '';

    public string $db_password = '';

    // -- Step 2: storage --------------------------------------------------

    /**
     * Defaults to Embedded so an operator who never touches this step still
     * gets a fully working, zero-configuration install -- see plan Task 5.
     */
    public string $storage_provider = StorageProvider::Embedded->value;

    public string $s3_endpoint = '';

    public string $s3_account = '';

    public string $s3_key = '';

    public string $s3_secret = '';

    public string $s3_bucket = '';

    public string $s3_region = '';

    // -- Step 3: admin ------------------------------------------------------

    public string $instance_name = 'doccum';

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Probe the database the operator entered without saving anything.
     *
     * A dry run for the "Test connection" affordance in the view -- saveDatabase()
     * performs the same probe itself before it writes anything, so this never
     * needs to be called for saveDatabase() to be safe.
     */
    public function testDatabase(): void
    {
        $this->resetErrorBag('db_connection');

        $result = app(ConnectionProbe::class)->database($this->databaseConfig());

        if (! $result->ok) {
            $this->addError('db_connection', $result->message ?? __('Could not connect to that database.'));
        }
    }

    /**
     * Probe the storage the operator entered without saving anything.
     */
    public function testStorage(): void
    {
        $this->resetErrorBag('s3_endpoint');

        $result = app(ConnectionProbe::class)->storage($this->storageConfig());

        if (! $result->ok) {
            $this->addError('s3_endpoint', $result->message ?? __('Could not reach that storage location.'));
        }
    }

    /**
     * Derives the endpoint (and, where the provider fixes one, the region)
     * from the chosen storage_provider preset -- see App\Enums\StorageProvider.
     *
     * A preset is a default, not a cage: a provider with no fixed endpoint
     * (plain S3, Custom) leaves s3_endpoint exactly as the operator typed it,
     * since endpointFor() returns null for those and nothing is overwritten.
     */
    public function previewEndpoint(): void
    {
        $provider = StorageProvider::tryFrom($this->storage_provider);

        if ($provider === null) {
            return;
        }

        $endpoint = $provider->endpointFor(
            $this->s3_account !== '' ? $this->s3_account : null,
            $this->s3_region !== '' ? $this->s3_region : null,
        );

        if ($endpoint !== null) {
            $this->s3_endpoint = $endpoint;
        }

        $defaultRegion = $provider->defaultRegion();

        if ($defaultRegion !== null) {
            $this->s3_region = $defaultRegion;
        }
    }

    /**
     * Probe, inspect, and only then -- if the database is either provably
     * empty or provably an already-encrypted doccum instance -- persist the
     * database block to the runtime file, apply it to live config, purge the
     * stale connection, and migrate.
     *
     * The ordering is deliberate and load-bearing, and must not be rearranged:
     *
     *   1. Probe the connection. On failure, addError and stop -- nothing
     *      written.
     *   2. inspect() on that SAME kind of temporary probe connection, before
     *      anything is persisted, switched or migrated.
     *   3. On key_mismatch: addError, write nothing, migrate nothing, switch
     *      nothing.
     *   4. Only then write the runtime file, apply to live config, purge, and
     *      migrate.
     *   5. On populated, set attaching = true and redirect to login -- never
     *      to the storage or admin steps, since a populated database must
     *      never be offered an admin-creation form. On fresh, advance to the
     *      storage step.
     *
     * Switching database.default before knowing the database is ours would
     * poison the running request with an unvalidated connection; migrating
     * first would create doccum's tables inside a stranger's database.
     */
    /**
     * The path and the database name share one field, so switching driver must
     * not carry a filesystem path over as a database name (or vice versa).
     */
    public function updatedDbConnection(string $value): void
    {
        $this->db_database = $value === 'sqlite' ? '/data/doccum.sqlite' : '';
        $this->db_port = match ($value) {
            'pgsql' => '5432',
            'mysql', 'mariadb' => '3306',
            default => '',
        };
        $this->resetErrorBag();
    }

    /** Reveal the database and storage steps. */
    public function enterAdvanced(): void
    {
        $this->advanced = true;
        $this->step = 1;
        $this->resetErrorBag();
    }

    /** Abandon advanced configuration and take the embedded defaults. */
    public function leaveAdvanced(): void
    {
        $this->advanced = false;
        $this->step = 3;
        $this->resetErrorBag();
    }

    public function saveDatabase(): void
    {
        $this->resetErrorBag('db_connection');

        $config = $this->databaseConfig();
        $connection = (string) $config['connection'];

        $probe = app(ConnectionProbe::class)->database($config);

        if (! $probe->ok) {
            $this->addError('db_connection', $probe->message ?? __('Could not connect to that database.'));

            return;
        }

        $state = app(ConnectionProbe::class)->inspect($config);

        if ($state === InstanceState::KeyMismatch) {
            $this->addError('db_connection', __(
                'This database belongs to a doccum instance encrypted with a different APP_KEY. '
                .'Restore the original APP_KEY to attach to it.'
            ));

            return;
        }

        $this->applyDatabase($config, $connection);

        // Supervised workers still hold a connection to whatever database was
        // configured when they started. Left alone they would process jobs
        // against the bootstrap SQLite -- silently wrong rather than broken.
        $this->workersRestarted = SupervisedProcesses::restartWorkers();

        if ($state === InstanceState::Populated) {
            $this->attaching = true;
            $this->redirect(route('login'));

            return;
        }

        $this->step = 2;
    }

    /**
     * Choosing Embedded with no credential fields touched hides every field
     * in the view and needs no probe -- there is nothing to get wrong. Any
     * other provider, OR Embedded with legacy s3_* fields still populated
     * (an operator pointed at a self-hosted MinIO before this preset existed),
     * falls through to the normal probe-then-write path below.
     */
    private function isEmbeddedWithNoOverrides(): bool
    {
        return $this->storage_provider === StorageProvider::Embedded->value
            && $this->s3_endpoint === ''
            && $this->s3_account === ''
            && $this->s3_key === ''
            && $this->s3_secret === ''
            && $this->s3_bucket === ''
            && $this->s3_region === '';
    }

    /**
     * Probe, then write each value through Settings -- setSecret() for the
     * access secret, plain set() for the rest. Storage never touches the
     * runtime file: only the database connection belongs there.
     */
    public function saveStorage(): void
    {
        $this->resetErrorBag('s3_endpoint');

        if ($this->isEmbeddedWithNoOverrides()) {
            app(Settings::class)->set('storage.provider', StorageProvider::Embedded->value);

            $this->step = 3;

            return;
        }

        $this->previewEndpoint();

        $config = $this->storageConfig();
        $provider = StorageProvider::tryFrom($this->storage_provider);

        // The addressing style a chosen provider needs is derived, not typed
        // by the operator, so it rides along on the probe without ever being
        // written to Settings -- RuntimeConfigServiceProvider re-derives it
        // from storage.provider on every boot instead (see plan Task 3).
        $probeConfig = $provider !== null
            ? $config + ['use_path_style_endpoint' => $provider->usesPathStyle()]
            : $config;

        $probe = app(ConnectionProbe::class)->storage($probeConfig);

        if (! $probe->ok) {
            $this->addError('s3_endpoint', $probe->message ?? __('Could not reach that storage location.'));

            return;
        }

        $settings = app(Settings::class);

        foreach (Arr::except($config, ['secret']) as $key => $value) {
            $settings->set("storage.{$key}", $value);
        }

        if (array_key_exists('secret', $config)) {
            $settings->setSecret('storage.secret', $config['secret']);
        }

        $this->step = 3;
    }

    /**
     * Keep whatever storage is already configured (the "documents" disk's
     * environment defaults) and move on.
     */
    public function skipStorage(): void
    {
        $this->step = 3;
    }

    public function submit(): Redirector|RedirectResponse
    {
        // Defence in depth. RequireInstanceSetup 404s the /setup ROUTE once a
        // user exists, but Livewire method calls arrive at /livewire/update and
        // pass straight through that check -- so without this, a direct call
        // could still mint a second admin on an instance that already has one,
        // which is precisely the attach-mode danger.
        abort_if(User::query()->exists(), 404);

        // The entrypoint seeds roles at boot, but the installer may since have
        // migrated a DIFFERENT database -- one the entrypoint never saw. Roles
        // are structure, and seeding is idempotent, so ensure them here too.
        Artisan::call('doccum:ensure-roles');

        // Fold before validating: this form bypasses Fortify's own
        // RegisteredUserController entirely (see App\Actions\Fortify\
        // CreateNewUser for why that matters), so nothing upstream folds the
        // admin's email for it. See App\Support\EmailKey and issue #59.
        $this->email = EmailKey::of($this->email);

        $validated = $this->validate([
            'instance_name' => ['required', 'string', 'max:191'],
            'name' => $this->nameRules(),
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $user->assignRole('admin');

        $settings = app(Settings::class);
        $settings->set('instance.name', $validated['instance_name'], $user->id);

        // A canary only this APP_KEY can decrypt. Lets a later installer run
        // recognise this exact database as an already-configured doccum
        // instance -- and refuse to attach if APP_KEY has since changed,
        // rather than yield a half-working instance. See ConnectionProbe::inspect().
        $settings->set('instance.key_check', encrypt('doccum'), $user->id);

        app(CreateHomeDirectory::class)->handle($user);

        Auth::login($user);

        return redirect('/');
    }

    /** @return array<string, string> */
    private function databaseConfig(): array
    {
        return array_filter([
            'connection' => $this->db_connection,
            'host' => $this->db_host,
            'port' => $this->db_port,
            'database' => $this->db_database,
            'username' => $this->db_username,
            'password' => $this->db_password,
        ], fn (string $value): bool => $value !== '');
    }

    /** @return array<string, string> */
    private function storageConfig(): array
    {
        return array_filter([
            'provider' => $this->storage_provider,
            'endpoint' => $this->s3_endpoint,
            'account' => $this->s3_account,
            'key' => $this->s3_key,
            'secret' => $this->s3_secret,
            'bucket' => $this->s3_bucket,
            'region' => $this->s3_region,
        ], fn (string $value): bool => $value !== '');
    }

    /** @param  array<string, string>  $config */
    private function applyDatabase(array $config, string $connection): void
    {
        RuntimeConfig::write(['database' => $config]);

        config()->set('database.default', $connection);
        config()->set("database.connections.{$connection}", array_merge(
            config("database.connections.{$connection}", []),
            Arr::except($config, ['connection']),
        ));

        DB::purge($connection);

        Artisan::call('migrate', ['--force' => true]);
    }

    public function render()
    {
        return view('livewire.setup.first-run');
    }
}
