<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Role;

it('grants access to a user', function () {
    $dir = Directory::factory()->create();
    $user = User::factory()->create();

    $grant = DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => AccessLevel::Edit,
    ]);

    expect($grant->fresh()->level)->toBe(AccessLevel::Edit)
        ->and($grant->fresh()->grantee->is($user))->toBeTrue();
});

it('grants access to a role', function () {
    $dir = Directory::factory()->create();
    $role = Role::findOrCreate('member', 'web');

    $grant = DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'role',
        'grantee_id' => $role->id,
        'level' => AccessLevel::View,
    ]);

    expect($grant->fresh()->grantee->is($role))->toBeTrue();
});

it('stores short morph aliases rather than class names', function () {
    $dir = Directory::factory()->create();
    $user = User::factory()->create();

    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => AccessLevel::View,
    ]);

    expect(DB::table('directory_access')->value('grantee_type'))->toBe('user');
});

it('refuses two grants to the same grantee on one directory', function () {
    $dir = Directory::factory()->create();
    $user = User::factory()->create();
    $attrs = ['directory_id' => $dir->id, 'grantee_type' => 'user', 'grantee_id' => $user->id];

    DirectoryGrant::create([...$attrs, 'level' => AccessLevel::View]);

    expect(fn () => DirectoryGrant::create([...$attrs, 'level' => AccessLevel::Edit]))
        ->toThrow(QueryException::class);
});
