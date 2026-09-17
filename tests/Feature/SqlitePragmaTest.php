<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Embedded SQLite pragmas -- decision/0010, issue #92
|--------------------------------------------------------------------------
|
| The single container runs FrankenPHP, two queue workers and the scheduler
| against one SQLite file, with queue, cache and session all on the database
| driver. Stock Laravel leaves busy_timeout, journal_mode and synchronous at
| null, which means "whatever the driver decides", and SQLite decides on a
| busy timeout of zero -- the loser of a write race fails immediately instead
| of waiting. That is what printed
|
|     General error: 5 database is locked
|     (SQL: update "jobs" set "reserved_at" = ..., "attempts" = 1 ...)
|
| on main, twice. These assertions read the pragmas back out of a live
| connection built from doccum's own sqlite configuration, so they fail if
| the configuration goes back to inheriting the driver's defaults.
|
| Deliberately file-backed: the suite's own connection is ":memory:", where
| journal_mode is always "memory" and WAL is unobservable. Runs on every leg
| of the matrix -- pdo_sqlite is installed for all six (tests.yml:228), and
| this adds its own connection rather than touching the default one, which
| RefreshDatabase resolves lazily at rollback.
*/

/**
 * A live connection on a real file, configured exactly as doccum configures
 * the embedded database -- only the path differs.
 */
function pragmaProbeConnection(string $path): Connection
{
    touch($path);

    config(['database.connections.pragma_probe' => array_merge(
        config('database.connections.sqlite'),
        ['database' => $path],
    )]);

    return DB::connection('pragma_probe');
}

/**
 * `pragma busy_timeout` answers in a column called `timeout`, `pragma
 * journal_mode` in one called `journal_mode`. Read the first value rather
 * than naming each column.
 */
function pragmaValue(Connection $connection, string $pragma): int|string|null
{
    $row = (array) $connection->select("pragma {$pragma}")[0];

    /** @var int|string|null $value */
    $value = reset($row);

    return $value;
}

beforeEach(function () {
    $this->pragmaProbePath = sys_get_temp_dir().'/doccum-pragma-'.uniqid().'.sqlite';
});

afterEach(function () {
    DB::purge('pragma_probe');

    foreach (['', '-wal', '-shm'] as $suffix) {
        $file = $this->pragmaProbePath.$suffix;

        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('waits rather than failing when the embedded database is locked', function () {
    $connection = pragmaProbeConnection($this->pragmaProbePath);

    expect(pragmaValue($connection, 'busy_timeout'))->toBe(5000);
});

it('journals the embedded database in WAL mode so readers do not block writers', function () {
    $connection = pragmaProbeConnection($this->pragmaProbePath);

    expect(pragmaValue($connection, 'journal_mode'))->toBe('wal');
});

it('states a synchronous level rather than inheriting one', function () {
    $connection = pragmaProbeConnection($this->pragmaProbePath);

    // FULL -- the same level SQLite itself defaults to, named here so it is a
    // decision rather than an inheritance. A document archive must not lose
    // the metadata row for an object already in the store. SQLite reports the
    // level numerically: 0 OFF, 1 NORMAL, 2 FULL.
    expect(pragmaValue($connection, 'synchronous'))->toBe(2);
});

it('leaves the pragmas overridable, so a network filesystem can turn WAL off', function () {
    config(['database.connections.sqlite.journal_mode' => 'DELETE']);

    $connection = pragmaProbeConnection($this->pragmaProbePath);

    expect(pragmaValue($connection, 'journal_mode'))->toBe('delete');
});
