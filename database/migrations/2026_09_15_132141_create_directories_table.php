<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('directories')->cascadeOnDelete();
            $table->string('name', 255);
            // 512 rather than 1024: MySQL caps an index key at 3072 bytes, which
            // is 768 characters of utf8mb4. 512 is over 50 levels of nesting.
            $table->string('path', 512)->default('')->index();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->foreignId('home_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            // A plain index, not unique. A unique index including deleted_at
            // enforces nothing, because SQL treats NULLs as distinct and every
            // live row has deleted_at NULL; partial unique indexes are not
            // portable to MySQL. Uniqueness among non-trashed siblings is
            // enforced in the application.
            $table->index(['parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directories');
    }
};
