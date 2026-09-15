<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Actions\Users\CreateHomeDirectory;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\ConnectionProbe;
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
     * Probe, then -- and only on success -- persist the database block to the
     * runtime file, apply it to live config, purge the stale connection, and
     * migrate so the chosen database has the schema before anything else
     * touches it.
     *
     * The ordering is deliberate and load-bearing: switching database.default
     * before the probe succeeds would poison the running request with a
     * connection nobody has validated, and migrating before that would create
     * doccum's tables inside whatever the operator typed in, valid or not.
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

        $this->applyDatabase($config, $connection);

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

        app(Settings::class)->set('instance.name', $validated['instance_name'], $user->id);
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
