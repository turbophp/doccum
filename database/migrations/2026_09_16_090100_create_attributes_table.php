<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_definition_id')->constrained()->cascadeOnDelete();
            // No foreign key: attributable_id can point at either directories
            // or files, so the relationship is enforced in the application
            // (Directory::attributes() / File::attributes() plus their
            // forceDeleted hooks) rather than in the schema.
            $table->string('attributable_type', 32);
            $table->unsignedBigInteger('attributable_id');
            $table->string('value_string', 1024)->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->timestamps();

            $table->unique(['attribute_definition_id', 'attributable_type', 'attributable_id'], 'attributes_unique_per_subject');
            $table->index(['attributable_type', 'attributable_id']);
            $table->index(['attribute_definition_id', 'value_string']);
            $table->index(['attribute_definition_id', 'value_number']);
            $table->index(['attribute_definition_id', 'value_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};
