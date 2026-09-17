<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_definition_id')->constrained()->cascadeOnDelete();
            // No foreign key: subject_id can point at either directories
            // or files, so the relationship is enforced in the application
            // (Directory::properties() / File::properties() plus their
            // forceDeleted hooks) rather than in the schema.
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('value_string', 1024)->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->timestamps();

            $table->unique(['property_definition_id', 'subject_type', 'subject_id'], 'properties_unique_per_subject');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['property_definition_id', 'value_number']);
            $table->index(['property_definition_id', 'value_date']);
        });

        // value_string is indexed on its own because MySQL and PostgreSQL
        // each cap an index tuple below what the full column can hold, and
        // each needed a different remedy (issues #37/#38, #39; decision/0005).
        //
        // MySQL/MariaDB: InnoDB caps a key at 3072 bytes, and VARCHAR(1024)
        // under utf8mb4 is up to 4096 before the definition id is even added,
        // so `$table->index([...])` fails the migration outright -- which is
        // why doccum could never be installed on the MySQL its own README
        // recommends. It gets a 255-character prefix. That is a genuine
        // prefix index on the column itself, not an expression: an ordinary
        // `value_string = ?`/`LIKE 'foo%'` query still uses it with no query
        // rewrite, for values of any length -- InnoDB re-checks the full row
        // whenever the prefix alone cannot decide the match.
        //
        // PostgreSQL: no prefix-length syntax exists for an index -- the
        // only way to shrink what goes into the tuple is a genuine
        // expression index, and Postgres only ever uses one when the query
        // is written against that identical expression (unlike MySQL's
        // prefix, which is transparent to the query). A truncation
        // expression (`left(value_string, 255)`) was rejected for exactly
        // that reason: it can serve equality, prefix and ordering, but only
        // for queries spelled with that same `left(...)` call, and it is
        // still an approximation that needs the real `value_string = ?`
        // kept in the query for correctness whenever two values share a
        // 255-character prefix. This column has no ordering or prefix use
        // to protect -- spec §4 defines this index for "lookup by value"
        // (equality) only; range/date comparisons live in value_number and
        // value_date, and prefix/substring search lives in search_documents,
        // not here (see the comment below this one). So the index is built
        // on `md5(value_string)` instead: a fixed 32-character hash, always
        // far under the ~2704-byte tuple ceiling regardless of how long
        // value_string gets. It serves ONLY an equality lookup, and only
        // when the query adds `md5(value_string) = md5(?)` alongside the
        // real `value_string = ?` predicate (the real predicate must stay,
        // both for correctness against an md5 collision and because
        // Postgres will not use this index for a plain `value_string = ?`
        // on its own). It cannot serve ordering or prefix/LIKE matching at
        // all -- item/search-filters' property filter must add that second
        // predicate for the string/text/select data types when it targets
        // PostgreSQL, or this index is dead weight the planner never picks.
        //
        // SQLite has no such ceiling (see SchemaPortabilityTest), so it
        // indexes the column whole -- the same as every driver did before
        // this fix existed.
        //
        // Shortening value_string was already rejected in #38: it would cut
        // what a property can hold to work around a storage-engine limit.
        $expression = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'value_string(255)',
            'pgsql' => 'md5(value_string)',
            default => 'value_string',
        };

        DB::statement(
            'CREATE INDEX properties_property_definition_id_value_string_index '
            ."ON properties (property_definition_id, {$expression})"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
