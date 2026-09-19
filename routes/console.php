<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Closing is safe and idempotent, so it runs nightly. Purging runs weekly but
// deletes nothing unless retention.auto_purge is explicitly switched on --
// see App\Console\Commands\PurgeExpired.
Schedule::command('doccum:close-periods')->dailyAt('02:10');
Schedule::command('doccum:purge-expired')->weeklyOn(1, '03:10');

// Hourly, not daily: spec §11's staging prefix is where an abandoned
// presigned upload sits, and the default --older-than (60 minutes) is
// meant to be swept within the hour it goes stale, not once a day. See
// App\Console\Commands\SweepUploads.
Schedule::command('doccum:sweep-uploads')->hourly();
