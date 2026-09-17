<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One flattened projection backing all search, rather than making
        // Directory/File/Property directly searchable: extracted text lives
        // on file_texts and property values on properties, columns that do
        // not exist on directories or files. See spec §8.
        Schema::create('search_documents', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('title', 512);
            $table->longText('body')->nullable();
            $table->foreignId('directory_id')->nullable()->constrained('directories')->cascadeOnDelete();
            $table->json('ancestor_ids');
            $table->unsignedSmallInteger('period_year')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('mime', 191)->nullable();
            $table->string('extension', 16)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index('directory_id');
            $table->index(['period_year', 'period_month']);

            // foreignId()->constrained() does not index the column itself --
            // only MySQL/MariaDB gets one, auto-created by InnoDB because the
            // constraint requires it. SQLite and PostgreSQL leave it bare.
            // See tests/Feature/SchemaPortabilityTest.php (decision/0008).
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_documents');
    }
};
