<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            // (Directory::attributes() / File::attributes() plus their
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
            $table->index(['property_definition_id', 'value_string']);
            $table->index(['property_definition_id', 'value_number']);
            $table->index(['property_definition_id', 'value_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
