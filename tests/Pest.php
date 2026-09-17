<?php

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Grant a user or a role access to a directory.
 *
 * This lived at the top of DirectoryAccessTest, which meant it only existed
 * when that file happened to be loaded: a full run declared it, and
 * `--filter` on any other file that calls it died with "Call to undefined
 * function grant()" -- a confusing failure, in a workflow CLAUDE.md documents
 * as ordinary. Three test files call it now, so it belongs here.
 */
function grant(Directory $dir, $grantee, AccessLevel $level): DirectoryGrant
{
    return DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => $grantee instanceof Role ? 'role' : 'user',
        'grantee_id' => $grantee->id,
        'level' => $level,
    ]);
}
