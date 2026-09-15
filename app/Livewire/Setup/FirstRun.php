<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Actions\Users\CreateHomeDirectory;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\ConnectionProbe;
use App\Services\InstanceState;
use App\Services\Settings;
use App\Support\RuntimeConfig;
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
    public int $step = 1;

    /**
     * True once saveDatabase() has recognised the database as an already-
     * populated doccum instance rather than a fresh one. Storage and admin
     * creation must never be reachable once this is true -- see saveDatabase().
     */
    public bool $attaching = false;

    // -- Step 1: database ------------------------------------------------

    public string $db_connection = 'sqlite';

    public string $db_host = '';

    public string $db_port = '';

    public string $db_database = '';

    public string $db_username = '';

    public string $db_password = '';

    // -- Step 2: storage --------------------------------------------------

    public string $s3_endpoint = '';

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

        if ($state === InstanceState::Populated) {
            $this->attaching = true;
            $this->redirect(route('login'));

            return;
        }

        $this->step = 2;
    }

    /**
     * Probe, then write each value through Settings -- setSecret() for the
     * access secret, plain set() for the rest. Storage never touches the
     * runtime file: only the database connection belongs there.
     */
    public function saveStorage(): void
    {
        $this->resetErrorBag('s3_endpoint');

        $config = $this->storageConfig();

        $probe = app(ConnectionProbe::class)->storage($config);

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
            'endpoint' => $this->s3_endpoint,
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
