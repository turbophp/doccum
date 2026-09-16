<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The FTS5 virtual table backing keyword search.
     *
     * SQLite only, and guarded rather than assumed: other drivers get
     * LikeSearchIndex until tsvector lands, and running this against them
     * would fail the migration outright. rowid mirrors search_documents.id so
     * the two stay in step without triggers.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement(
            "CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(title, body, tokenize = 'unicode61')"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::dropIfExists('search_index');
    }
};
