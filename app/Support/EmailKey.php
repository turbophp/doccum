<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The comparison key behind email uniqueness and login -- lowercase-on-write,
 * ratifying what MySQL's utf8mb4_unicode_ci column collation already does for
 * this column, and what essentially every mail provider does in practice.
 *
 * Unlike App\Support\NameKey, this key is NOT accent-sensitive and does NOT
 * NFC-normalise. NameKey needed both because a displayed filename must keep
 * its original case (only a side column is folded) and macOS clients submit
 * NFD filenames that must collide with their NFC twin. Email has no display
 * requirement to preserve -- the folded value IS the stored value -- and no
 * accented-domain report has ever motivated NFC handling here, so inventing
 * one would be a semantic no report backs, not one this rule states. If an
 * internationalised domain ever needs it, that is a new, argued-for rule,
 * not a silent extension of this one.
 *
 * Folding is case only: trim (an address with incidental leading/trailing
 * whitespace is the same address) then lowercase, Unicode-aware via
 * mb_strtolower rather than SQL's LOWER() -- SQLite's LOWER() is ASCII-only
 * without ICU, exactly the driver-dependent expression decision/0010 rules
 * out. See issue #59.
 *
 * Pure and database-free, exactly like NameKey and ObjectKey.
 */
final class EmailKey
{
    public static function of(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
