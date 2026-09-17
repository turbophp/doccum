<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
            $table->string('name', 255);
            // No foreign key: files and file_versions reference each other, and
            // adding a circular constraint afterwards is not portable to
            // SQLite. The relationship is enforced in the application.
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('mime', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->unsignedSmallInteger('period_year')->index();
            $table->unsignedTinyInteger('period_month')->index();
            $table->boolean('legal_hold')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['directory_id', 'name']);

            // foreignId()->constrained() does not index the column itself --
            // only MySQL/MariaDB gets one, auto-created by InnoDB because the
            // constraint requires it. SQLite and PostgreSQL leave it bare.
            // See tests/Feature/SchemaPortabilityTest.php (decision/0008).
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
