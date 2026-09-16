<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately its own table rather than columns on `files`: a
        // directory listing must never drag megabytes of OCR text along
        // with it just because a row happens to sit next to it.
        Schema::create('file_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('extractor', 32)->nullable();
            $table->longText('text')->nullable();
            $table->unsignedInteger('chars')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_texts');
    }
};
