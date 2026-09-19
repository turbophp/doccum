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

// Hourly is safe and idempotent, same as closing periods: a staging object
// is only ever swept once it is well past any upload_id's own TTL (spec
// §11), so a client mid-upload is never at risk of losing what it just PUT.
// See App\Console\Commands\SweepUploads.
Schedule::command('doccum:sweep-uploads')->hourly();
