<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Gives a user their personal space.
 *
 * A home directory is an ordinary root-level directory with home_user_id set
 * and the username as its name -- no separate table, no second permission
 * concept. Its owner gets an ordinary `manage` grant, so the resolver treats it
 * exactly like any other. See spec §4 and §5.
 */
class CreateHomeDirectory
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(User $user): ?Directory
    {
        if ($this->settings->get('directories.auto_home') !== true) {
            return null;
        }

        $existing = Directory::query()->where('home_user_id', $user->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($user): Directory {
            $home = Directory::create([
                'parent_id' => null,
                'name' => $user->username,
                'home_user_id' => $user->getKey(),
                'created_by' => $user->getKey(),
            ]);

            DirectoryGrant::create([
                'directory_id' => $home->getKey(),
                'grantee_type' => 'user',
                'grantee_id' => $user->getKey(),
                'level' => AccessLevel::Manage,
            ]);

            return $home->refresh();
        });
    }
}
