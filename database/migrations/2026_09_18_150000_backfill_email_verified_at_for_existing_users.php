<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * item/email-verification-decided (issue #161) made User implement
 * MustVerifyEmail and put every authenticated route -- dashboard, files.*,
 * search, trash, directories.archives.download, all of admin.* -- behind
 * ['auth', 'verified']. App\Livewire\Setup\FirstRun marks the administrator
 * it creates as verified, which answers the no-mailer default (MAIL_MAILER=
 * log in .env.example) for a FRESH install. Nothing answered it for an
 * instance that already existed: the only migration ever touching
 * email_verified_at is the framework's own create_users_table, which just
 * creates the nullable column, so every user made before that item shipped
 * has email_verified_at = NULL. Upgrading the image then locks every one of
 * them -- including the administrator, out of admin.* too -- out of the
 * entire route group, with no way back in through the UI on a container
 * whose mailer is a log file on the server. See issue #210.
 *
 * The semantic: before item/email-verification-decided, email verification
 * enforced nothing at all, so every existing account already had full,
 * unconditional access to everything `verified` now gates. Backfilling them
 * to verified is not granting anything new -- it is restoring exactly the
 * access they already had, which is what "the upgrade did not break the
 * instance" has to mean. The timestamp used is `created_at`, not now(): what
 * is actually true of these accounts is that they have been trusted since
 * they were made, not since the moment this migration happened to run.
 *
 * Scope is `email_verified_at IS NULL` and nothing else -- no filter on
 * created_at, no role check. That column is ALSO set back to NULL by
 * App\Livewire\Settings\Profile the instant an operator changes their own
 * email post-upgrade, deliberately, because the new address has proved
 * nothing yet. This migration must never re-verify that kind of NULL, and it
 * structurally cannot: a Laravel migration runs at most once per database
 * (recorded in the `migrations` table the moment it completes), and this one
 * runs during the very upgrade that first ships the code able to null the
 * column post-install at all. docker/entrypoint.d migrates
 * (50-laravel-automations.sh, part of the base image) before the app server
 * is started -- see 49-doccum-init.sh's own comment on that ordering -- so no
 * HTTP request can reach Profile::updateProfileInformation() and null a row
 * before this migration has already run to completion. At the one moment
 * this UPDATE executes, every NULL row in `users` therefore predates email
 * verification meaning anything at all; a "deliberately null" row cannot yet
 * exist. On a fresh install there are zero users when this runs (FirstRun
 * has not created the administrator yet either -- database migration
 * happens before step 3 of the wizard), so the UPDATE matches nothing and
 * this migration is a correct no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    /**
     * Deliberately not reversed. Which rows this migration touched is not
     * recoverable -- there is nothing in the row itself distinguishing "was
     * NULL, backfilled by this migration" from "was already verified before
     * it ran" -- so a down() that nulled anything would be a guess dressed
     * up as a rollback. Leaving existing users verified on a rollback is
     * also the safer failure: it preserves access rather than reintroducing
     * the exact lockout this migration exists to fix.
     */
    public function down(): void
    {
        //
    }
};
