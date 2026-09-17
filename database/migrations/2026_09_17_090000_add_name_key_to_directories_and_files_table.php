<?php

declare(strict_types=1);

use App\Support\NameKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sibling names compare case-insensitively and accent-sensitively, after
     * NFC normalisation (App\Support\NameKey, issue #46's decision comment).
     * `name_key` is the persisted key that comparison runs against, so the
     * rule means one thing on every driver instead of three different things.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // Laravel's SQLite and PostgreSQL grammars both emit a COLLATE clause
        // whenever ->collation() is set, and `utf8mb4_bin` is not a valid
        // collation on either -- an unconditional call breaks those two
        // migrations outright. `_bin`, not a `utf8mb4_0900_*` collation:
        // MariaDB has no `0900` family, and this column must work on both.
        $mysqlLike = in_array($driver, ['mysql', 'mariadb'], true);

        Schema::table('directories', function (Blueprint $table) use ($mysqlLike) {
            $column = $table->string('name_key', 255)->nullable()->after('name');

            if ($mysqlLike) {
                $column->collation('utf8mb4_bin');
            }
        });

        Schema::table('files', function (Blueprint $table) use ($mysqlLike) {
            $column = $table->string('name_key', 255)->nullable()->after('name');

            if ($mysqlLike) {
                $column->collation('utf8mb4_bin');
            }
        });

        $this->backfill('directories');
        $this->backfill('files');

        // Pre-existing collisions are tolerated, not fatal: two live siblings
        // that only ever differed by case or accent folding now share a key.
        // This is pre-1.0 data, so it is logged for an operator to look at
        // and the rule is enforced going forward rather than blocking the
        // upgrade.
        $this->logCollisions('directories', 'parent_id');
        $this->logCollisions('files', 'directory_id');

        // Create before dropping, and in that order on purpose. parent_id and
        // directory_id are foreign keys, and InnoDB requires an index whose
        // leading column is the constrained one. The old composite index was
        // serving that role, so dropping it first leaves the constraint
        // unindexed and MySQL refuses with errno 1553. The new composite
        // index leads with the same column, so once it exists the old one is
        // redundant and can go. PostgreSQL and SQLite do not require an index
        // for a foreign key at all, which is why only MySQL objects.
        Schema::table('directories', function (Blueprint $table) {
            $table->index(['parent_id', 'name_key']);
            $table->dropIndex(['parent_id', 'name']);
        });

        Schema::table('files', function (Blueprint $table) {
            $table->index(['directory_id', 'name_key']);
            $table->dropIndex(['directory_id', 'name']);
        });
    }

    public function down(): void
    {
        // Same ordering constraint as up(), reversed: the index the foreign
        // key will fall back on has to exist before the one it is using now
        // is dropped.
        Schema::table('directories', function (Blueprint $table) {
            $table->index(['parent_id', 'name']);
            $table->dropIndex(['parent_id', 'name_key']);
            $table->dropColumn('name_key');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->index(['directory_id', 'name']);
            $table->dropIndex(['directory_id', 'name_key']);
            $table->dropColumn('name_key');
        });
    }

    /**
     * Chunked so a large install does not load every row into memory at
     * once. Ordered by id so a chunk boundary is stable across pages even
     * though this update changes none of the columns being ordered on.
     */
    private function backfill(string $table): void
    {
        DB::table($table)->select(['id', 'name'])
            ->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['name_key' => NameKey::of($row->name)]);
                }
            });
    }

    /**
     * Groups live (non-trashed) siblings by their new key and logs any group
     * with more than one member. Nothing here fails the migration -- it is
     * purely a record for an operator to act on.
     */
    private function logCollisions(string $table, string $parentColumn): void
    {
        $collisions = DB::table($table)
            ->select([$parentColumn, 'name_key'])
            ->whereNull('deleted_at')
            ->groupBy([$parentColumn, 'name_key'])
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($collisions as $collision) {
            Log::warning('name-key collision among existing siblings', [
                'table' => $table,
                $parentColumn => $collision->{$parentColumn},
                'name_key' => $collision->name_key,
            ]);
        }
    }
};
