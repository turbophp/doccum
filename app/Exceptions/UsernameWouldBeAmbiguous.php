<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a username containing `@` is about to be persisted.
 *
 * AuthenticateUser resolves a login identifier by asking whether it contains
 * `@`: with one it looks up an email, without one a username. That dispatch
 * is only unambiguous while the two character sets stay disjoint, and the
 * consequence of them overlapping is not a cosmetic bug -- a username equal
 * to another account's email would send the typing user to THAT account's
 * row, which is an account-takeover shape rather than a failed login.
 *
 * usernameRules() already excludes `@` at every application write path. This
 * exists because "every write path remembers" is a convention, and
 * authentication should not rest on one: User::booted() raises this so no
 * path -- a factory, a seeder, a future admin screen, a console command
 * nobody has written yet -- can persist a username the dispatch cannot read
 * back. See issue #75.
 */
class UsernameWouldBeAmbiguous extends RuntimeException
{
    public static function forUsername(string $username): self
    {
        return new self(
            "Username [{$username}] contains '@', which would make a login identifier ambiguous with an email address."
        );
    }
}
