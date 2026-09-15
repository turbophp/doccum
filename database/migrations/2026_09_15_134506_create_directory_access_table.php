<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('directory_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
            $table->string('grantee_type', 32);
            $table->unsignedBigInteger('grantee_id');
            $table->string('level', 16);
            $table->timestamps();

            $table->unique(['directory_id', 'grantee_type', 'grantee_id']);
            $table->index(['grantee_type', 'grantee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('directory_access');
    }
};
