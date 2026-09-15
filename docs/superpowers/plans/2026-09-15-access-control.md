# doccum Access Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make doccum multi-user: global capabilities via Spatie roles, per-directory access inherited down the subtree, per-user home directories, a first-run admin setup screen, and public signup as an operator toggle.

**Architecture:** Two independent authorisation layers that must BOTH pass — Spatie answers "what kind of action may this person ever perform?", `directory_access` answers "where?". A single `DirectoryAccess` service owns all resolution; policies call it and contain no rules of their own.

**Tech Stack:** Laravel 13, Livewire 4, spatie/laravel-permission ^8.3, Pest 5.

**Spec:** `docs/superpowers/specs/2026-09-15-doccum-design.md` (§5 Access control, §4 data model, §10 surface)

**Previous plan:** `docs/superpowers/plans/2026-09-15-foundation.md` (complete, merged)

## Global Constraints

- **Never edit anything under `vendor/`.** No patches, no forks.
- **Framework classes are consumed, not subclassed**, except at documented extension points.
- **One registration point:** every macro, binding, morph map and policy registration goes in `app/Providers/DoccumServiceProvider.php`.
- **Framework tables are only added to.** Never edit a framework migration.
- `declare(strict_types=1);` at the top of every PHP file authored, migrations included.
- Models declare fillability with the `#[Fillable([...])]` attribute — this codebase's idiom, not `protected $fillable`.
- Pest for all tests; every task ends with a green FULL suite and a commit.
- Baseline entering this plan: **78 tests, 170 assertions**.

---

## File Structure

| Path | Responsibility |
|---|---|
| `app/Enums/AccessLevel.php` | The ordered levels `view < edit < manage`, and comparison. |
| `app/Models/DirectoryGrant.php` | One row of `directory_access`. |
| `app/Services/DirectoryAccess.php` | The ONLY place access is resolved. Memoised per request. |
| `app/Policies/DirectoryPolicy.php` | Delegates to `DirectoryAccess`; no rules of its own. |
| `app/Policies/FilePolicy.php` | Same, via the file's directory. |
| `app/Actions/Users/CreateHomeDirectory.php` | Creates a user's home directory and its `manage` grant. |
| `app/Http/Middleware/EnsurePublicSignupEnabled.php` | 404s the register routes when signup is off. |
| `app/Http/Middleware/RequireInstanceSetup.php` | Redirects to first-run setup while no user exists. |
| `app/Livewire/Setup/FirstRun.php` + view | The one-time setup screen. |
| `database/seeders/RolesAndPermissionsSeeder.php` | Seeds capabilities and the default roles. |

**Naming note:** the model is `DirectoryGrant` on table `directory_access`, because `App\Services\DirectoryAccess` already owns that name and two `DirectoryAccess` classes in different namespaces would be a permanent readability tax.

---

### Task 1: Install Spatie and seed roles

**Files:**
- Modify: `composer.json`, `app/Models/User.php`, `app/Providers/DoccumServiceProvider.php`
- Create: `database/seeders/RolesAndPermissionsSeeder.php`
- Test: `tests/Feature/RolesAndPermissionsTest.php`

**Interfaces:**
- Produces: the `admin` and `member` roles; permissions `files.upload`, `files.delete`, `files.restore`, `directories.create`, `directories.manage`, `attributes.manage`, `users.manage`, `periods.manage`, `directories.view-all`; `User` gains Spatie's `HasRoles`.

- [ ] **Step 1: Install the package**

```bash
composer require spatie/laravel-permission:^8.3
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class));

it('seeds the admin and member roles', function () {
    expect(Role::pluck('name')->all())->toContain('admin', 'member');
});

it('gives admin every permission', function () {
    $admin = Role::findByName('admin');

    expect($admin->permissions->pluck('name')->all())
        ->toContain('users.manage', 'directories.view-all', 'periods.manage', 'files.delete');
});

it('gives member only day-to-day capabilities', function () {
    $names = Role::findByName('member')->permissions->pluck('name')->all();

    expect($names)->toContain('files.upload', 'directories.create')
        ->and($names)->not->toContain('users.manage')
        ->and($names)->not->toContain('directories.view-all')
        ->and($names)->not->toContain('periods.manage');
});

it('lets a user hold a role and its permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    expect($user->can('files.upload'))->toBeTrue()
        ->and($user->can('users.manage'))->toBeFalse();
});

it('is idempotent when seeded twice', function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);

    expect(Role::where('name', 'admin')->count())->toBe(1);
});
```

- [ ] **Step 3: Run it and watch it fail**

`php artisan test --filter=RolesAndPermissionsTest` — expected FAIL, seeder class not found.

- [ ] **Step 4: Add `HasRoles` to the User model**

Add `use Spatie\Permission\Traits\HasRoles;` and `use HasRoles;` inside the class.

- [ ] **Step 5: Write the seeder**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Capabilities answer "what may this person ever do"; directory_access
     * answers "where". Both must pass. See spec §5.
     */
    public const PERMISSIONS = [
        'files.upload',
        'files.delete',
        'files.restore',
        'directories.create',
        'directories.manage',
        'attributes.manage',
        'users.manage',
        'periods.manage',
        // The admin bypass of per-directory access.
        'directories.view-all',
    ];

    public const MEMBER_PERMISSIONS = [
        'files.upload',
        'files.delete',
        'files.restore',
        'directories.create',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('admin', 'web')->syncPermissions(self::PERMISSIONS);
        Role::findOrCreate('member', 'web')->syncPermissions(self::MEMBER_PERMISSIONS);
    }
}
```

- [ ] **Step 6: Run the focused test, then the full suite**

Expected: 5 passing; full suite green.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock app/Models/User.php database/seeders/RolesAndPermissionsSeeder.php \
        database/migrations tests/Feature/RolesAndPermissionsTest.php config/permission.php
git commit -m "feat: add spatie roles and seeded capability permissions"
```

---

### Task 2: Access levels and the grant table

**Files:**
- Create: `app/Enums/AccessLevel.php`, `app/Models/DirectoryGrant.php`, `database/factories/DirectoryGrantFactory.php`, migration
- Modify: `app/Providers/DoccumServiceProvider.php` (morph map)
- Test: `tests/Feature/DirectoryGrantTest.php`, `tests/Unit/AccessLevelTest.php`

**Interfaces:**
- Produces: `AccessLevel::View|Edit|Manage`, `AccessLevel::allows(self $required): bool`, `AccessLevel::rank(): int`; `DirectoryGrant` with `directory_id`, morph `grantee`, `level`.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccessLevel;

it('orders view below edit below manage', function () {
    expect(AccessLevel::View->rank())->toBeLessThan(AccessLevel::Edit->rank())
        ->and(AccessLevel::Edit->rank())->toBeLessThan(AccessLevel::Manage->rank());
});

it('allows only levels at or below itself', function () {
    expect(AccessLevel::Manage->allows(AccessLevel::View))->toBeTrue()
        ->and(AccessLevel::Manage->allows(AccessLevel::Manage))->toBeTrue()
        ->and(AccessLevel::View->allows(AccessLevel::Edit))->toBeFalse()
        ->and(AccessLevel::Edit->allows(AccessLevel::View))->toBeTrue();
});

it('picks the highest of a set', function () {
    expect(AccessLevel::highest([AccessLevel::View, AccessLevel::Manage, AccessLevel::Edit]))
        ->toBe(AccessLevel::Manage)
        ->and(AccessLevel::highest([]))->toBeNull();
});
```

- [ ] **Step 2: Write the failing feature test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
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
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 3: Run both, watch them fail**

- [ ] **Step 4: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessLevel: string
{
    case View = 'view';
    case Edit = 'edit';
    case Manage = 'manage';

    public function rank(): int
    {
        return match ($this) {
            self::View => 1,
            self::Edit => 2,
            self::Manage => 3,
        };
    }

    public function allows(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /** @param  array<int, self>  $levels */
    public static function highest(array $levels): ?self
    {
        return array_reduce(
            $levels,
            static fn (?self $carry, self $level): self => $carry === null || $level->rank() > $carry->rank() ? $level : $carry,
        );
    }
}
```

- [ ] **Step 5: Write the migration**

```php
Schema::create('directory_access', function (Blueprint $table) {
    $table->id();
    $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
    $table->string('grantee_type', 32);
    $table->unsignedBigInteger('grantee_id');
    $table->string('level', 16);
    $table->timestamps();

    $table->unique(['directory_id', 'grantee_type', 'grantee_id']);
    $table->index(['grantee_type', 'grantee_id']);
});
```

- [ ] **Step 6: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One grant on table `directory_access`.
 *
 * Named DirectoryGrant because App\Services\DirectoryAccess already owns the
 * other name, and two DirectoryAccess classes would be a readability tax.
 */
#[Fillable(['directory_id', 'grantee_type', 'grantee_id', 'level'])]
class DirectoryGrant extends Model
{
    use HasFactory;

    protected $table = 'directory_access';

    protected function casts(): array
    {
        return ['level' => AccessLevel::class];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    public function grantee(): MorphTo
    {
        return $this->morphTo();
    }
}
```

- [ ] **Step 7: Register the morph map**

In `DoccumServiceProvider::boot()` — the single registration point:

```php
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Role;

Relation::enforceMorphMap([
    'user' => User::class,
    'role' => Role::class,
    'directory' => Directory::class,
    'file' => File::class,
]);
```

A morph map keeps fully-qualified class names out of the database, so moving or
renaming a class later is a code change rather than a data migration.

- [ ] **Step 8: Write the factory, run both tests, then the full suite**

- [ ] **Step 9: Commit**

---

### Task 3: The access resolver

**Files:**
- Create: `app/Services/DirectoryAccess.php`
- Test: `tests/Feature/DirectoryAccessTest.php`

**Interfaces:**
- Produces:
  - `DirectoryAccess::levelFor(User $user, Directory $directory): ?AccessLevel`
  - `DirectoryAccess::can(User $user, Directory $directory, AccessLevel $required): bool`
  - `DirectoryAccess::viewableDirectoryIds(User $user): array<int, int>`

Every later plan reads access through these three methods. Nothing else resolves access.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use App\Services\DirectoryAccess;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->root = Directory::factory()->create();
    $this->mid = Directory::factory()->for($this->root, 'parent')->create();
    $this->leaf = Directory::factory()->for($this->mid, 'parent')->create();
    $this->elsewhere = Directory::factory()->create();
    $this->user = User::factory()->create();
});

function grant(Directory $dir, $grantee, AccessLevel $level): DirectoryGrant
{
    return DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => $grantee instanceof Role ? 'role' : 'user',
        'grantee_id' => $grantee->id,
        'level' => $level,
    ]);
}

it('returns null where nothing is granted', function () {
    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBeNull();
});

it('inherits a grant down the whole subtree', function () {
    grant($this->root, $this->user, AccessLevel::Edit);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit)
        ->and(app(DirectoryAccess::class)->levelFor($this->user, $this->mid))->toBe(AccessLevel::Edit);
});

it('does not leak a grant sideways', function () {
    grant($this->root, $this->user, AccessLevel::Manage);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->elsewhere))->toBeNull();
});

it('takes the highest level among ancestors', function () {
    grant($this->root, $this->user, AccessLevel::View);
    grant($this->mid, $this->user, AccessLevel::Manage);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Manage);
});

it('never lowers access from a narrower grant', function () {
    grant($this->root, $this->user, AccessLevel::Manage);
    grant($this->leaf, $this->user, AccessLevel::View);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Manage);
});

it('resolves grants made to a role the user holds', function () {
    $role = Role::findByName('member');
    $this->user->assignRole($role);
    grant($this->root, $role, AccessLevel::Edit);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);
});

it('combines user and role grants, taking the highest', function () {
    $role = Role::findByName('member');
    $this->user->assignRole($role);
    grant($this->root, $role, AccessLevel::View);
    grant($this->mid, $this->user, AccessLevel::Edit);

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);
});

it('gives manage everywhere to a holder of directories.view-all', function () {
    $this->user->assignRole('admin');

    expect(app(DirectoryAccess::class)->levelFor($this->user, $this->elsewhere))->toBe(AccessLevel::Manage);
});

it('answers can() against the required level', function () {
    grant($this->root, $this->user, AccessLevel::Edit);
    $access = app(DirectoryAccess::class);

    expect($access->can($this->user, $this->leaf, AccessLevel::View))->toBeTrue()
        ->and($access->can($this->user, $this->leaf, AccessLevel::Edit))->toBeTrue()
        ->and($access->can($this->user, $this->leaf, AccessLevel::Manage))->toBeFalse();
});

it('expands viewable ids to whole subtrees', function () {
    grant($this->mid, $this->user, AccessLevel::View);

    $ids = app(DirectoryAccess::class)->viewableDirectoryIds($this->user);

    expect($ids)->toContain($this->mid->id, $this->leaf->id)
        ->and($ids)->not->toContain($this->root->id)
        ->and($ids)->not->toContain($this->elsewhere->id);
});

it('returns every directory id for an admin', function () {
    $this->user->assignRole('admin');

    expect(app(DirectoryAccess::class)->viewableDirectoryIds($this->user))
        ->toHaveCount(Directory::count());
});

it('returns an empty list for a user with no grants', function () {
    expect(app(DirectoryAccess::class)->viewableDirectoryIds($this->user))->toBe([]);
});

it('sees a grant written after an earlier resolution', function () {
    $access = app(DirectoryAccess::class);

    expect($access->levelFor($this->user, $this->leaf))->toBeNull();

    grant($this->root, $this->user, AccessLevel::Edit);

    expect($access->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);
});

it('sees a grant revoked after an earlier resolution', function () {
    $access = app(DirectoryAccess::class);
    $granted = grant($this->root, $this->user, AccessLevel::Edit);

    expect($access->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);

    $granted->delete();

    expect($access->levelFor($this->user, $this->leaf))->toBeNull();
});

it('sees a level raised after an earlier resolution', function () {
    $access = app(DirectoryAccess::class);
    grant($this->root, $this->user, AccessLevel::View);

    expect($access->can($this->user, $this->leaf, AccessLevel::Manage))->toBeFalse();

    DirectoryGrant::query()->delete();
    grant($this->root, $this->user, AccessLevel::Manage);

    expect($access->can($this->user, $this->leaf, AccessLevel::Manage))->toBeTrue();
});

it('sees a directory moved out of a granted subtree', function () {
    $access = app(DirectoryAccess::class);
    grant($this->root, $this->user, AccessLevel::Edit);

    expect($access->levelFor($this->user, $this->leaf))->toBe(AccessLevel::Edit);

    app(App\Actions\Directories\MoveDirectory::class)->handle($this->leaf->fresh(), $this->elsewhere->fresh());

    expect($access->levelFor($this->user, $this->leaf->fresh()))->toBeNull();
});
```

- [ ] **Step 2: Run it and watch it fail**

- [ ] **Step 3: Write the service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of truth for per-directory access. See spec §5.
 *
 * Grants are inherited by a directory's entire subtree, and the effective level
 * is the HIGHEST grant found on the directory or any ancestor, across grants
 * made to the user directly and to any role they hold.
 *
 * Grant-only: there are no deny rules. Deny semantics in an inherited tree
 * produce surprising results and expensive resolution; a narrower grant lower
 * in the tree covers the legitimate cases.
 */
class DirectoryAccess
{
    /** @var array<string, AccessLevel|null> */
    private array $levels = [];

    /** @var array<int, array<int, int>> */
    private array $viewable = [];

    public function levelFor(User $user, Directory $directory): ?AccessLevel
    {
        $key = $user->getKey().':'.$directory->getKey();

        return $this->levels[$key] ??= $this->resolve($user, $directory);
    }

    public function can(User $user, Directory $directory, AccessLevel $required): bool
    {
        return $this->levelFor($user, $directory)?->allows($required) ?? false;
    }

    /**
     * Every directory id the user may at least view, granted subtrees expanded.
     *
     * This is what search filters against, so it must never include a directory
     * the user cannot reach.
     *
     * @return array<int, int>
     */
    public function viewableDirectoryIds(User $user): array
    {
        return $this->viewable[$user->getKey()] ??= $this->resolveViewable($user);
    }

    private function resolve(User $user, Directory $directory): ?AccessLevel
    {
        if ($user->can('directories.view-all')) {
            return AccessLevel::Manage;
        }

        $levels = $this->grantsFor($user)
            ->whereIn('directory_id', $directory->ancestorIds())
            ->pluck('level')
            ->all();

        return AccessLevel::highest($levels);
    }

    /** @return array<int, int> */
    private function resolveViewable(User $user): array
    {
        if ($user->can('directories.view-all')) {
            return Directory::query()->pluck('id')->all();
        }

        $paths = Directory::query()
            ->whereIn('id', $this->grantsFor($user)->pluck('directory_id'))
            ->pluck('path');

        if ($paths->isEmpty()) {
            return [];
        }

        return Directory::query()
            ->where(function (Builder $query) use ($paths): void {
                foreach ($paths as $path) {
                    $query->orWhere('path', 'like', $path.'%');
                }
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Drop the memoised answers.
     *
     * The memoisation above is only safe while nothing has changed what a grant
     * means. Because this service is a singleton, a caller that resolves access,
     * then writes a grant, then resolves again would otherwise get the stale
     * answer -- a correctness hole in the one component the whole authorisation
     * story rests on. Invalidation is wired to grant writes AND to directory
     * writes, because subtree membership is derived from the materialised path,
     * so moving a directory changes who can reach it.
     */
    public function flush(): void
    {
        $this->levels = [];
        $this->viewable = [];
    }

    private function grantsFor(User $user): Builder
    {
        $roleIds = $user->roles->pluck('id')->all();

        return DirectoryGrant::query()->where(function (Builder $query) use ($user, $roleIds): void {
            $query->where(fn (Builder $q) => $q->where('grantee_type', 'user')->where('grantee_id', $user->getKey()));

            if ($roleIds !== []) {
                $query->orWhere(fn (Builder $q) => $q->where('grantee_type', 'role')->whereIn('grantee_id', $roleIds));
            }
        });
    }
}
```

Register it as a singleton in `DoccumServiceProvider::register()` so the
memoisation actually holds for a request:

```php
$this->app->singleton(DirectoryAccess::class);
```

Then wire invalidation in `DoccumServiceProvider::boot()`. Without this the
singleton returns stale answers for the rest of the request after any grant
change — see the `flush()` docblock:

```php
$flushAccess = static fn (): null => tap(null, fn () => app(DirectoryAccess::class)->flush());

DirectoryGrant::saved($flushAccess);
DirectoryGrant::deleted($flushAccess);
// Subtree membership comes from the materialised path, so a move changes
// who can reach a directory just as much as a grant does.
Directory::saved($flushAccess);
Directory::deleted($flushAccess);
```

If `tap` reads awkwardly, a plain closure calling `app(DirectoryAccess::class)->flush()`
is equivalent — the point is that all four events clear the memo.

- [ ] **Step 4: Run the focused test, then the full suite**

Expected: 12 passing.

- [ ] **Step 5: Commit**

---

### Task 4: Policies

**Files:**
- Create: `app/Policies/DirectoryPolicy.php`, `app/Policies/FilePolicy.php`
- Modify: `app/Providers/DoccumServiceProvider.php`
- Test: `tests/Feature/DirectoryPolicyTest.php`, `tests/Feature/FilePolicyTest.php`

**Interfaces:**
- Produces: `view`, `create`, `update`, `delete`, `manageAccess` on `DirectoryPolicy`; `view`, `download`, `create`, `update`, `delete` on `FilePolicy`. Both require the Spatie capability AND the directory level.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;

beforeEach(function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

function give(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

it('denies viewing without a grant', function () {
    expect($this->user->can('view', $this->dir))->toBeFalse();
});

it('allows viewing with a view grant', function () {
    give($this->dir, $this->user, AccessLevel::View);

    expect($this->user->can('view', $this->dir))->toBeTrue();
});

it('requires edit to upload into a directory', function () {
    give($this->dir, $this->user, AccessLevel::View);
    expect($this->user->can('create', [File::class, $this->dir]))->toBeFalse();

    DirectoryGrant::query()->delete();
    give($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->can('create', [File::class, $this->dir]))->toBeTrue();
});

it('requires the capability as well as the level', function () {
    // A user with manage on the directory but no files.upload capability.
    $stranger = User::factory()->create();
    give($this->dir, $stranger, AccessLevel::Manage);

    expect($stranger->can('create', [File::class, $this->dir]))->toBeFalse();
});

it('requires manage to grant access', function () {
    give($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->can('manageAccess', $this->dir))->toBeFalse();

    DirectoryGrant::query()->delete();
    give($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->can('manageAccess', $this->dir))->toBeTrue();
});

it('lets a file inherit its directory access', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('view', $file))->toBeFalse();

    give($this->dir, $this->user, AccessLevel::View);
    expect($this->user->fresh()->can('view', $file))->toBeTrue();
});

it('lets an admin reach anything', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($admin->can('view', $this->dir))->toBeTrue()
        ->and($admin->can('view', $file))->toBeTrue()
        ->and($admin->can('manageAccess', $this->dir))->toBeTrue();
});
```

Note the capability test deliberately uses a user with a high directory level
and no capability — that is the case a single-layer implementation gets wrong.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write `DirectoryPolicy`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\User;
use App\Services\DirectoryAccess;

/**
 * Delegates every decision to DirectoryAccess. No rules live here: both the UI
 * and the API authorise through these policies, so one place must own them.
 */
class DirectoryPolicy
{
    public function __construct(private readonly DirectoryAccess $access) {}

    public function view(User $user, Directory $directory): bool
    {
        return $this->access->can($user, $directory, AccessLevel::View);
    }

    public function create(User $user, Directory $parent): bool
    {
        return $user->can('directories.create')
            && $this->access->can($user, $parent, AccessLevel::Edit);
    }

    public function update(User $user, Directory $directory): bool
    {
        return $this->access->can($user, $directory, AccessLevel::Edit);
    }

    public function delete(User $user, Directory $directory): bool
    {
        return $user->can('directories.manage')
            && $this->access->can($user, $directory, AccessLevel::Manage);
    }

    public function manageAccess(User $user, Directory $directory): bool
    {
        return $this->access->can($user, $directory, AccessLevel::Manage);
    }
}
```

- [ ] **Step 4: Write `FilePolicy`**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;

class FilePolicy
{
    public function __construct(private readonly DirectoryAccess $access) {}

    public function view(User $user, File $file): bool
    {
        return $this->access->can($user, $file->directory, AccessLevel::View);
    }

    public function download(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    public function create(User $user, Directory $directory): bool
    {
        return $user->can('files.upload')
            && $this->access->can($user, $directory, AccessLevel::Edit);
    }

    public function update(User $user, File $file): bool
    {
        return $this->access->can($user, $file->directory, AccessLevel::Edit);
    }

    public function delete(User $user, File $file): bool
    {
        return $user->can('files.delete')
            && $this->access->can($user, $file->directory, AccessLevel::Edit);
    }
}
```

- [ ] **Step 5: Register the policies in `DoccumServiceProvider::boot()`**

```php
Gate::policy(Directory::class, DirectoryPolicy::class);
Gate::policy(File::class, FilePolicy::class);
```

- [ ] **Step 6: Run both focused tests, then the full suite. Commit.**

---

### Task 5: Home directories

**Files:**
- Create: `app/Actions/Users/CreateHomeDirectory.php`
- Modify: `app/Actions/Fortify/CreateNewUser.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/HomeDirectoryTest.php`

**Interfaces:**
- Produces: `CreateHomeDirectory::handle(User $user): ?Directory` — creates a root directory named for the username with `home_user_id` set, plus a `manage` grant for its owner. Returns null when `directories.auto_home` is off.
- Also produces a `UserFactory::withHome()` state.

**Design note, read before implementing:** this is invoked EXPLICITLY from the
real user-creation paths, not from a `User::created` model event. A model
observer would fire inside `User::factory()`, so every existing test that counts
directories would silently gain rows — `DirectoryPathTest` asserts
`Directory::count()` exactly. Explicit invocation also keeps the action
composable and visible at the call sites that matter.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\Users\CreateHomeDirectory;
use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\User;
use App\Services\DirectoryAccess;
use App\Services\Settings;

it('creates a home directory named for the username', function () {
    $user = User::factory()->create(['username' => 'ada']);

    $home = app(CreateHomeDirectory::class)->handle($user);

    expect($home->name)->toBe('ada')
        ->and($home->home_user_id)->toBe($user->id)
        ->and($home->parent_id)->toBeNull()
        ->and($home->path)->toBe("/{$home->id}/");
});

it('gives the owner manage on their home', function () {
    $user = User::factory()->create(['username' => 'ada']);
    $home = app(CreateHomeDirectory::class)->handle($user);

    expect(app(DirectoryAccess::class)->levelFor($user, $home))->toBe(AccessLevel::Manage);
});

it('gives the owner access to nothing else', function () {
    $other = Directory::factory()->create();
    $user = User::factory()->create(['username' => 'ada']);
    app(CreateHomeDirectory::class)->handle($user);

    expect(app(DirectoryAccess::class)->levelFor($user, $other))->toBeNull();
});

it('is idempotent', function () {
    $user = User::factory()->create(['username' => 'ada']);

    $first = app(CreateHomeDirectory::class)->handle($user);
    $second = app(CreateHomeDirectory::class)->handle($user);

    expect($second->id)->toBe($first->id)
        ->and(Directory::where('home_user_id', $user->id)->count())->toBe(1);
});

it('does nothing when auto home is disabled', function () {
    app(Settings::class)->set('directories.auto_home', false);
    $user = User::factory()->create(['username' => 'ada']);

    expect(app(CreateHomeDirectory::class)->handle($user))->toBeNull()
        ->and(Directory::count())->toBe(0);
});

it('creates a home when a user registers', function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'ada',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect(Directory::where('home_user_id', $user->id)->value('name'))->toBe('ada')
        ->and($user->hasRole('member'))->toBeTrue();
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the action**

```php
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
```

- [ ] **Step 4: Call it from registration**

In `app/Actions/Fortify/CreateNewUser.php`, after `User::create(...)`, assign the
default role and create the home directory:

```php
        $user = User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $role = app(Settings::class)->get('auth.default_role');

        if (is_string($role) && Role::where('name', $role)->exists()) {
            $user->assignRole($role);
        }

        app(CreateHomeDirectory::class)->handle($user);

        return $user;
```

- [ ] **Step 5: Add a `withHome()` factory state**

```php
    public function withHome(): static
    {
        return $this->afterCreating(function (User $user): void {
            app(\App\Actions\Users\CreateHomeDirectory::class)->handle($user);
        });
    }
```

- [ ] **Step 6: Run focused, then full suite. Commit.**

---

### Task 6: Public signup toggle

**Files:**
- Create: `app/Http/Middleware/EnsurePublicSignupEnabled.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/PublicSignupTest.php`

**Interfaces:**
- Produces: register routes return 404 unless `auth.public_signup` is true.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\Settings;

beforeEach(fn () => $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class));

it('hides the register page by default', function () {
    $this->get(route('register'))->assertNotFound();
});

it('refuses a registration post by default', function () {
    $this->post(route('register.store'), [
        'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(App\Models\User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('serves the register page when the operator enables signup', function () {
    app(Settings::class)->set('auth.public_signup', true);

    $this->get(route('register'))->assertOk();
});

it('accepts a registration when signup is enabled', function () {
    app(Settings::class)->set('auth.public_signup', true);

    $this->post(route('register.store'), [
        'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(App\Models\User::where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('leaves login reachable regardless', function () {
    $this->get(route('login'))->assertOk();
});
```

A 404 rather than a disabled form is deliberate: an instance should not
advertise an entry point it will not honour.

- [ ] **Step 2: Run and watch it fail** (existing registration tests will need
`auth.public_signup` enabled — update them in Step 4.)

- [ ] **Step 3: Write the middleware**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicSignupEnabled
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store') && $this->settings->get('auth.public_signup') !== true) {
            abort(404);
        }

        return $next($request);
    }
}
```

Attached to the whole web group and self-filtering by route name, because the
register routes are registered by Fortify's own provider and appending
middleware to them from outside would mean reaching into that package.

- [ ] **Step 4: Register it in `bootstrap/app.php`**

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->appendToGroup('web', App\Http\Middleware\EnsurePublicSignupEnabled::class);
})
```

Then update `tests/Feature/Auth/RegistrationTest.php` and the registration tests
in `tests/Feature/UsernameTest.php` and `tests/Feature/HomeDirectoryTest.php` to
enable the setting first:

```php
beforeEach(fn () => app(App\Services\Settings::class)->set('auth.public_signup', true));
```

- [ ] **Step 5: Run focused, then full suite. Commit.**

---

### Task 7: First-run setup screen

**Files:**
- Create: `app/Http/Middleware/RequireInstanceSetup.php`, `app/Livewire/Setup/FirstRun.php`, `resources/views/livewire/setup/first-run.blade.php`
- Modify: `routes/web.php`, `bootstrap/app.php`
- Test: `tests/Feature/FirstRunSetupTest.php`

**Interfaces:**
- Produces: `/setup` creating the first admin; every other web route redirects there while no user exists; `/setup` 404s once one does.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Directory;
use App\Models\User;
use App\Services\Settings;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class));

it('redirects to setup while the instance has no users', function () {
    $this->get('/')->assertRedirect(route('setup'));
    $this->get(route('login'))->assertRedirect(route('setup'));
});

it('serves the setup screen when there are no users', function () {
    $this->get(route('setup'))->assertOk();
});

it('creates the first admin with a home directory', function () {
    Livewire::test(App\Livewire\Setup\FirstRun::class)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $user = User::firstOrFail();

    expect($user->username)->toBe('ada')
        ->and($user->hasRole('admin'))->toBeTrue()
        ->and(app(Settings::class)->get('instance.name'))->toBe('Acme Docs')
        ->and(Directory::where('home_user_id', $user->id)->value('name'))->toBe('ada')
        ->and(auth()->check())->toBeTrue();
});

it('validates the first admin', function () {
    Livewire::test(App\Livewire\Setup\FirstRun::class)
        ->set('username', 'Not A Username!')
        ->set('email', 'nope')
        ->call('submit')
        ->assertHasErrors(['username', 'email', 'name', 'password']);

    expect(User::count())->toBe(0);
});

it('closes setup once a user exists', function () {
    User::factory()->create();

    $this->get(route('setup'))->assertNotFound();
});

it('stops redirecting once a user exists', function () {
    User::factory()->create();

    $this->get(route('login'))->assertOk();
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the middleware**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the instance has no users at all, every web route leads to the one-time
 * setup screen; once one exists, that screen is gone for good.
 *
 * This is why doccum ships no default credentials: there is never a moment
 * where a known username and password would work.
 */
class RequireInstanceSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $hasUsers = User::query()->exists();

        if (! $hasUsers && ! $request->routeIs('setup')) {
            return redirect()->route('setup');
        }

        if ($hasUsers && $request->routeIs('setup')) {
            abort(404);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Write the Livewire component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Actions\Users\CreateHomeDirectory;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class FirstRun extends Component
{
    use PasswordValidationRules, ProfileValidationRules;

    public string $instance_name = 'doccum';

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function submit()
    {
        $validated = $this->validate([
            'instance_name' => ['required', 'string', 'max:191'],
            'name' => $this->nameRules(),
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $user->assignRole('admin');

        app(Settings::class)->set('instance.name', $validated['instance_name'], $user->id);
        app(CreateHomeDirectory::class)->handle($user);

        Auth::login($user);

        return redirect('/');
    }

    public function render()
    {
        return view('livewire.setup.first-run');
    }
}
```

- [ ] **Step 5: Write the view**

Use `<x-layouts::auth>` and `flux:input` fields mirroring
`resources/views/livewire/auth/register.blade.php`, with a `wire:submit="submit"`
form and fields `instance_name`, `name`, `username`, `email`, `password`,
`password_confirmation`. Read the register view first and match its structure.

- [ ] **Step 6: Register the route and middleware**

`routes/web.php`:

```php
Route::get('/setup', App\Livewire\Setup\FirstRun::class)->name('setup');
```

`bootstrap/app.php` — append to the web group, before the signup middleware:

```php
$middleware->appendToGroup('web', App\Http\Middleware\RequireInstanceSetup::class);
```

- [ ] **Step 7: Run focused, then the full suite**

Existing tests that hit web routes will now redirect to `/setup` unless a user
exists. Where a test needs an instance that is already set up, create a user in
its `beforeEach`. Fix each on its merits — do not weaken the middleware.

- [ ] **Step 8: Commit**

---

## Done when

- A user with a grant on a directory reaches its whole subtree; a user without one reaches nothing, and neither the UI nor a future API can leak past that.
- Capability and location are both required: manage on a directory without `files.upload` still cannot upload.
- Every new user gets a home directory named for their username, `manage` on it, and access to nothing else.
- A fresh install redirects to `/setup`, creates the first admin, and closes that door permanently.
- Public signup is off by default and the register route 404s until an operator turns it on.
- `php artisan test` green; `docker compose up` still boots clean from empty volumes.
