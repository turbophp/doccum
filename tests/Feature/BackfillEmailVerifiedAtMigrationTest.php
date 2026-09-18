<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regression test for issue #210: item/email-verification-decided (#161)
 * put every authenticated route behind ['auth', 'verified'], but nothing
 * backfilled email_verified_at for users created before that item shipped
 * -- so upgrading an existing instance locked every one of them out, with
 * no recovery through the UI on a container whose mailer defaults to 'log'.
 *
 * database/migrations/2026_09_18_150000_backfill_email_verified_at_for_existing_users.php
 * is the fix. Asserting "email_verified_at is non-null after RefreshDatabase
 * migrates" would be vacuous: RefreshDatabase runs every migration,
 * including this one, against an EMPTY users table, so there is nothing yet
 * for it to backfill and the assertion would pass whether or not the
 * migration's UPDATE does anything at all. To make the assertion real, this
 * test inserts a raw row -- bypassing the User model and its factory
 * entirely, so nothing here defaults email_verified_at to a timestamp the
 * way UserFactory::definition() does -- simulating a user who already
 * existed on the instance before this migration ever ran, then invokes the
 * migration's own up() a second time against that row, exactly as it would
 * run during a real upgrade against real pre-existing data.
 */
function backfillEmailVerifiedAtMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'database/migrations/2026_09_18_150000_backfill_email_verified_at_for_existing_users.php'
    );

    return $migration;
}

it('backfills email_verified_at for a user that predates email verification enforcement', function () {
    $createdAt = now()->subYear()->startOfSecond();

    DB::table('users')->insert([
        'name' => 'Pre-existing User',
        'username' => 'preexisting',
        'email' => 'preexisting@example.com',
        'email_verified_at' => null,
        'password' => 'irrelevant-hash',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    backfillEmailVerifiedAtMigration()->up();

    $row = DB::table('users')->where('email', 'preexisting@example.com')->first();

    // If the migration's UPDATE were removed (or the migration file itself
    // deleted, which fails the require() above outright), this row would
    // still be NULL -- exactly the state issue #210 reproduced on a real
    // container -- and this assertion would fail.
    expect($row->email_verified_at)->not->toBeNull();

    // The chosen semantic is specifically "verified since created_at", not
    // "verified now": these accounts already had full access before
    // verification was ever enforced, so the backfilled timestamp states
    // that fact rather than the moment the migration happened to run.
    expect($row->email_verified_at)->toBe($createdAt->toDateTimeString());
});

it('leaves an already-verified user untouched, proving the scope is email_verified_at IS NULL and not every row', function () {
    $originalVerifiedAt = now()->subDays(3)->startOfSecond();
    $createdAt = now()->subDays(10)->startOfSecond();

    DB::table('users')->insert([
        'name' => 'Already Verified User',
        'username' => 'alreadyverified',
        'email' => 'verified@example.com',
        'email_verified_at' => $originalVerifiedAt,
        'password' => 'irrelevant-hash',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    backfillEmailVerifiedAtMigration()->up();

    $row = DB::table('users')->where('email', 'verified@example.com')->first();

    // A migration scoped to whereNull() only touches NULL rows. If the
    // WHERE clause were dropped and every row's email_verified_at were
    // overwritten unconditionally, this genuinely-verified timestamp would
    // be clobbered with created_at instead, and this assertion would fail.
    expect($row->email_verified_at)->toBe($originalVerifiedAt->toDateTimeString());
});

it('is a no-op against an empty users table, matching a fresh install', function () {
    expect(DB::table('users')->count())->toBe(0);

    backfillEmailVerifiedAtMigration()->up();

    expect(DB::table('users')->count())->toBe(0);
});
