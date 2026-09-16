<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedBigInteger('byte_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_periods');
    }
};
