<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\User;
use App\Observers\SearchProjectionObserver;
use App\Policies\DirectoryPolicy;
use App\Policies\FilePolicy;
use App\Policies\PropertyDefinitionPolicy;
use App\Search\Fts5SearchIndex;
use App\Search\LikeSearchIndex;
use App\Search\SearchIndex;
use App\Services\DirectoryAccess;
use App\Support\ProcessRunner;
use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Spatie\Permission\Models\Role;

/**
 * The single registration point for doccum.
 *
 * Every macro, container binding, morph map, policy registration, and Blade
 * directive we add lives here, so auditing what this application changed about
 * Laravel during a framework upgrade is reading one file. See spec §3.
 */
class DoccumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // FTS5 where the driver has it, a correct-but-unranked fallback
        // elsewhere. Resolved at container level so nothing downstream has to
        // know which is in play.
        $this->app->singleton(SearchIndex::class, function ($app): SearchIndex {
            return DB::connection()->getDriverName() === 'sqlite'
                ? $app->make(Fts5SearchIndex::class)
                : $app->make(LikeSearchIndex::class);
        });

        $this->app->singleton(DirectoryAccess::class);

        // Singleton so a test's ProcessRunner::fake() state is visible to
        // every `app(ProcessRunner::class)` resolved afterwards, including
        // the one inside the extraction strategy under test.
        $this->app->singleton(ProcessRunner::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'directory' => Directory::class,
            'file' => File::class,
            'property' => Property::class,
        ]);

        // The memoisation in DirectoryAccess is only safe while nothing has
        // changed what a grant means. Because that service is a singleton, a
        // request that resolves access, writes a grant, then resolves again
        // would otherwise get the stale answer. Subtree membership is derived
        // from the materialised path, so a directory move invalidates access
        // answers just as much as a grant does. See DirectoryAccess::flush().
        $flushAccess = static fn (): null => tap(null, fn () => app(DirectoryAccess::class)->flush());

        DirectoryGrant::saved($flushAccess);
        DirectoryGrant::deleted($flushAccess);
        Directory::saved($flushAccess);
        Directory::deleted($flushAccess);

        Gate::policy(Directory::class, DirectoryPolicy::class);
        Gate::policy(File::class, FilePolicy::class);
        Gate::policy(PropertyDefinition::class, PropertyDefinitionPolicy::class);

        Directory::observe(SearchProjectionObserver::class);
        File::observe(SearchProjectionObserver::class);
        Property::observe(SearchProjectionObserver::class);

        $this->registerAzureDriver();
    }

    /**
     * Azure Blob is the one storage provider that is not S3-compatible, so it
     * needs its own Flysystem driver rather than an S3 preset (see
     * App\Enums\StorageProvider and plan Task 4). Laravel ships no built-in
     * "azure" driver, so Storage::extend() -- the documented extension point
     * -- is how the "documents" disk can be configured to use it.
     */
    private function registerAzureDriver(): void
    {
        Storage::extend('azure', function ($app, array $config) {
            $client = BlobServiceClient::fromConnectionString((string) $config['connection_string']);
            $container = $client->getContainerClient((string) $config['container']);
            $adapter = new AzureBlobStorageAdapter($container, (string) ($config['prefix'] ?? ''));

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
