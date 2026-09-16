<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label', 191);
            $table->string('data_type', 16);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('applies_to', 16)->default('both');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['applies_to', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
