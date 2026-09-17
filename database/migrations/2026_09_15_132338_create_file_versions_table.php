<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('object_key', 1024);
            $table->unsignedBigInteger('size');
            $table->string('mime', 191);
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['file_id', 'version_number']);

            // file_id is already covered by the unique index above, which
            // leads with it, but uploaded_by is not. foreignId()->constrained()
            // does not index the column itself -- only MySQL/MariaDB gets one,
            // auto-created by InnoDB because the constraint requires it.
            // SQLite and PostgreSQL leave it bare. See
            // tests/Feature/SchemaPortabilityTest.php (decision/0008).
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');
    }
};
