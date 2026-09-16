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

        // value_string is indexed on its own because MySQL cannot index the
        // whole column. InnoDB caps a key at 3072 bytes, and VARCHAR(1024)
        // under utf8mb4 is up to 4096 before the definition id is even added,
        // so `$table->index([...])` fails the migration outright -- which is
        // why doccum could never be installed on the MySQL its own README
        // recommends. MySQL gets a prefix; every other driver indexes the
        // column whole.
        //
        // Shortening value_string would have been the easier fix and the
        // wrong one: it would cut what a property can hold to work around a
        // storage-engine limit. A prefix costs nothing that matters here --
        // this index serves structured filtering on property values, and
        // full-text lives in search_documents, not in this column.
        $expression = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'value_string(255)',
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
