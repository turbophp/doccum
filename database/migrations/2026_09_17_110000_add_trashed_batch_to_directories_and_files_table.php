<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identifies the cascade a soft-deleted row belongs to.
 *
 * RestoreDirectory has to tell "trashed because its ancestor was" from
 * "trashed on its own beforehand", and deleted_at cannot answer that: it is
 * stored at second precision, so a file trashed moments before its directory
 * ends up with a byte-identical timestamp and gets resurrected by a restore
 * that was supposed to leave it alone. The relationship is stated here
 * instead of inferred from a collision. See issue #49.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            $table->uuid('trashed_batch')->nullable()->index();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->uuid('trashed_batch')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            $table->dropIndex(['trashed_batch']);
            $table->dropColumn('trashed_batch');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['trashed_batch']);
            $table->dropColumn('trashed_batch');
        });
    }
};
