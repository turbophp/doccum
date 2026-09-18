<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard database-notifications table.
 *
 * User has carried the Notifiable trait since the starter kit, so the methods
 * were there all along with nowhere to write to. The first producer is a
 * finished directory archive: zipping is asynchronous and can outlast the tab
 * that asked for it, so "your zip is ready" has to survive a page the person
 * has already navigated away from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell asks one question on every page load: how many unread
            // for this person. Without this it is a full scan of the table.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
