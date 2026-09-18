<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One request to zip a directory, and the progress of building it.
 *
 * Zipping is asynchronous because it cannot be anything else: a directory of
 * scans is gigabytes read out of object storage one object at a time, and a
 * request that does it inline holds a worker for minutes and dies to the
 * proxy's timeout with nothing to show. The row exists so the browser has
 * something to poll -- what was asked for, how far it has got, and where the
 * finished object landed.
 *
 * requested_by is not decoration. The archive contains exactly the files ONE
 * viewer could see when the job ran, so it is that viewer's to download and
 * nobody else's; the download controller checks this column, not the
 * directory's policy, because access can widen or narrow between the two
 * moments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_archives', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('directory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->index();
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('completed_files')->default(0);
            $table->string('object_key')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            // The browser polls "my archives for this directory, newest
            // first", which this composite serves and which also gives
            // requested_by an index starting with itself.
            $table->index(['requested_by', 'directory_id']);

            // directory_id needs one of its own, starting with itself:
            // decision/0008's errno 1553 rule, so dropping the column later
            // cannot be blocked by a foreign key with no usable index.
            $table->index('directory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_archives');
    }
};
