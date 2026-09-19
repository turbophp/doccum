<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
});

it('removes a stale staging object', function () {
    Storage::disk('documents')->put('uploads/stale/a.txt', 'stale bytes');
    touch(Storage::disk('documents')->path('uploads/stale/a.txt'), now()->subMinutes(90)->getTimestamp());

    $this->artisan('doccum:sweep-uploads')->assertSuccessful();

    expect(Storage::disk('documents')->exists('uploads/stale/a.txt'))->toBeFalse();
});

/**
 * The word "only" in this item's own doneWhen is the test: a sweep that
 * removes EVERYTHING under the staging prefix would pass a careless version
 * of the test above and destroy an upload still in flight. A fresh staging
 * object -- written just now, well inside the default 60-minute window --
 * must survive a sweep that happens to run while it sits there.
 */
it('leaves a fresh staging object alone', function () {
    Storage::disk('documents')->put('uploads/fresh/a.txt', 'fresh bytes');

    $this->artisan('doccum:sweep-uploads')->assertSuccessful();

    expect(Storage::disk('documents')->exists('uploads/fresh/a.txt'))->toBeTrue();
});

it('sweeps a stale object while a fresh one survives, in the same run', function () {
    Storage::disk('documents')->put('uploads/stale/a.txt', 'stale bytes');
    touch(Storage::disk('documents')->path('uploads/stale/a.txt'), now()->subMinutes(90)->getTimestamp());

    Storage::disk('documents')->put('uploads/fresh/a.txt', 'fresh bytes');

    $this->artisan('doccum:sweep-uploads')
        ->expectsOutputToContain('Swept 1 stale staging object(s).')
        ->assertSuccessful();

    expect(Storage::disk('documents')->exists('uploads/stale/a.txt'))->toBeFalse()
        ->and(Storage::disk('documents')->exists('uploads/fresh/a.txt'))->toBeTrue();
});

it('respects a custom --older-than window', function () {
    Storage::disk('documents')->put('uploads/recent/a.txt', 'recent bytes');
    touch(Storage::disk('documents')->path('uploads/recent/a.txt'), now()->subMinutes(10)->getTimestamp());

    $this->artisan('doccum:sweep-uploads', ['--older-than' => 5])->assertSuccessful();

    expect(Storage::disk('documents')->exists('uploads/recent/a.txt'))->toBeFalse();
});

it('never touches an object outside the staging prefix, however old', function () {
    Storage::disk('documents')->put('files/2024/03/abc/v1/a.txt', 'a real document version');
    touch(Storage::disk('documents')->path('files/2024/03/abc/v1/a.txt'), now()->subDays(5)->getTimestamp());

    $this->artisan('doccum:sweep-uploads')->assertSuccessful();

    expect(Storage::disk('documents')->exists('files/2024/03/abc/v1/a.txt'))->toBeTrue();
});
