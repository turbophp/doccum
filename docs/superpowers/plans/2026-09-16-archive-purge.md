# doccum Archive & Purge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator close a period, see what it holds, and — deliberately, never by accident — reclaim its storage.

**Architecture:** A period is a first-class row with a lifecycle: open → archived (read-only) → purged. Closing is automatic and safe. Purging is the only irreversible operation in doccum, so it is opt-in, dry-run by default, blocked by legal holds, and refuses anything that has not been archived and aged past the retention window.

**Tech Stack:** Laravel 13, Pest 5.

**Spec:** `docs/superpowers/specs/2026-09-15-doccum-design.md` §9 (archive and purge), §6 (period partitioning)

**Previous plans:** foundation, access-control, storage, installer, embedded-storage, properties, extraction, search — all merged.

## Global Constraints

- **Never edit anything under `vendor/`;** never edit a framework migration.
- `declare(strict_types=1);` on every PHP file authored, migrations included.
- Models use `#[Fillable([...])]`.
- Nothing outside `App\Services\DocumentStorage` resolves a disk; nothing outside `App\Support\ProcessRunner` shells out.
- **Never pass an interface to `toThrow()`** — Pest branches on `class_exists()`, false for interfaces.
- **Purge is irreversible. Every guard needs a test that fails when the guard is removed.** If a guard test passes on its first run, delete the guard and confirm it then fails before trusting it.
- Pest for all tests; each task ends with a green FULL suite and its own commit.
- Baseline entering this plan: **375 tests, 805 assertions**.

---

## File Structure

| Path | Responsibility |
|---|---|
| `app/Models/ArchivePeriod.php` | One period and its lifecycle. |
| `app/Services/PeriodCloser.php` | Rolls up a finished period: counts, bytes, archived. |
| `app/Services/PeriodPurger.php` | Deletes a period's rows and objects, behind its guards. |
| `app/Exceptions/PeriodNotPurgeable.php` | Why a purge was refused. |
| `app/Console/Commands/ClosePeriods.php` | Scheduled rollup. |
| `app/Console/Commands/PurgePeriod.php` | Dry-run by default. |
| `app/Console/Commands/PurgeExpired.php` | Retention window, off unless enabled. |

---

### Task 1: Periods and closing them

**Files:**
- Create: migration, `app/Models/ArchivePeriod.php`, factory, `app/Services/PeriodCloser.php`
- Test: `tests/Feature/Archive/PeriodCloserTest.php`

**Interfaces:**
- `ArchivePeriod` — `year`, `month`, `archived_at`, `purged_at`, `file_count`, `byte_count`, `notes`
- `ArchivePeriod::isArchived(): bool` / `isPurged(): bool`
- `PeriodCloser::close(int $year, ?int $month = null): ArchivePeriod`
- `PeriodCloser::closeFinished(): Collection<ArchivePeriod>` — every period that has ended

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\ArchivePeriod;
use App\Models\File;
use App\Services\PeriodCloser;

it('records what a period holds when it closes', function () {
    File::factory()->count(3)->create(['period_year' => 2024, 'period_month' => 3, 'size' => 1000]);
    File::factory()->create(['period_year' => 2024, 'period_month' => 4, 'size' => 500]);

    $period = app(PeriodCloser::class)->close(2024, 3);

    expect($period->file_count)->toBe(3)
        ->and($period->byte_count)->toBe(3000)
        ->and($period->isArchived())->toBeTrue();
});

it('counts trashed files too, because their objects still occupy storage', function () {
    File::factory()->count(2)->create(['period_year' => 2024, 'period_month' => 3, 'size' => 100]);
    $trashed = File::factory()->create(['period_year' => 2024, 'period_month' => 3, 'size' => 100]);
    $trashed->delete();

    expect(app(PeriodCloser::class)->close(2024, 3)->file_count)->toBe(3);
});

it('is idempotent', function () {
    File::factory()->create(['period_year' => 2024, 'period_month' => 3, 'size' => 100]);

    app(PeriodCloser::class)->close(2024, 3);
    app(PeriodCloser::class)->close(2024, 3);

    expect(ArchivePeriod::where('year', 2024)->where('month', 3)->count())->toBe(1);
});

it('refuses to close a period that has not ended', function () {
    $this->travelTo('2026-05-10');

    expect(fn () => app(PeriodCloser::class)->close(2026, 5))
        ->toThrow(InvalidArgumentException::class);
});

it('closes every finished period at once', function () {
    $this->travelTo('2026-05-10');
    File::factory()->create(['period_year' => 2026, 'period_month' => 3, 'size' => 10]);
    File::factory()->create(['period_year' => 2026, 'period_month' => 4, 'size' => 20]);
    File::factory()->create(['period_year' => 2026, 'period_month' => 5, 'size' => 30]);

    $closed = app(PeriodCloser::class)->closeFinished();

    // March and April have ended; May has not.
    expect($closed->pluck('month')->all())->toEqualCanonicalizing([3, 4]);
});

it('does not reopen a purged period', function () {
    $period = ArchivePeriod::factory()->create([
        'year' => 2024, 'month' => 3, 'archived_at' => now(), 'purged_at' => now(),
    ]);

    app(PeriodCloser::class)->close(2024, 3);

    expect($period->fresh()->purged_at)->not->toBeNull();
});
```

Counting trashed files matters: trash keeps objects, so a period's byte count is
a lie if it ignores them — and that number is what an operator decides on.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the migration**

```php
Schema::create('archive_periods', function (Blueprint $table) {
    $table->id();
    $table->unsignedSmallInteger('year');
    $table->unsignedTinyInteger('month')->nullable();
    $table->timestamp('archived_at')->nullable();
    $table->timestamp('purged_at')->nullable();
    $table->unsignedInteger('file_count')->default(0);
    $table->unsignedBigInteger('byte_count')->default(0);
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->unique(['year', 'month']);
});
```

- [ ] **Step 4: Write the model, factory and closer. Run focused, then full suite. Commit.**

---

### Task 2: An archived period is read-only

**Files:**
- Modify: `app/Actions/Files/StoreFileVersion.php`, `app/Actions/Files/RestoreFile.php`
- Create: `app/Exceptions/PeriodIsArchived.php`
- Test: `tests/Feature/Archive/ArchivedPeriodTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->user = User::factory()->create();
    $this->dir = Directory::factory()->create();
});

function uploadInto(string $name, string $contents = 'x'): File
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return app(StoreFileVersion::class)->handle(test()->user, test()->dir, $path, $name, 'text/plain');
}

it('refuses a new file in an archived period', function () {
    $this->travelTo('2026-03-15');
    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 3, 'archived_at' => now()]);

    expect(fn () => uploadInto('a.txt'))->toThrow(PeriodIsArchived::class);
});

it('refuses a new version of an existing file in an archived period', function () {
    $this->travelTo('2026-03-15');
    uploadInto('a.txt', 'first');

    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 3, 'archived_at' => now()]);

    // A file's versions all live under its creation period, so adding one to an
    // archived file would write into a period declared closed.
    expect(fn () => uploadInto('a.txt', 'second'))->toThrow(PeriodIsArchived::class);
});

it('allows uploads in an open period', function () {
    $this->travelTo('2026-03-15');
    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 2, 'archived_at' => now()]);

    expect(uploadInto('a.txt')->name)->toBe('a.txt');
});

it('allows uploads when the period row exists but is not archived', function () {
    $this->travelTo('2026-03-15');
    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 3, 'archived_at' => null]);

    expect(uploadInto('a.txt')->name)->toBe('a.txt');
});
```

- [ ] **Step 2: Run, implement, run. Commit.**

---

### Task 3: Purging, and everything that stops it

**Files:**
- Create: `app/Services/PeriodPurger.php`, `app/Exceptions/PeriodNotPurgeable.php`
- Test: `tests/Feature/Archive/PeriodPurgerTest.php`

**Interfaces:**
- `PeriodPurger::plan(int $year, ?int $month): PurgePlan` — what would go, and why it may not
- `PeriodPurger::purge(int $year, ?int $month): PurgeReport` — throws `PeriodNotPurgeable` unless every guard passes

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Exceptions\PeriodNotPurgeable;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\SearchDocument;
use App\Services\PeriodPurger;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->travelTo('2026-06-01');
    $this->dir = Directory::factory()->create();
});

function fileIn(int $year, int $month, array $attributes = []): File
{
    return File::factory()->for(test()->dir, 'directory')->create(
        ['period_year' => $year, 'period_month' => $month, 'size' => 100] + $attributes
    );
}

function archived(int $year, int $month): ArchivePeriod
{
    return ArchivePeriod::factory()->create([
        'year' => $year, 'month' => $month, 'archived_at' => now()->subYears(3),
    ]);
}

it('refuses a period that was never archived', function () {
    fileIn(2020, 3);

    expect(fn () => app(PeriodPurger::class)->purge(2020, 3))
        ->toThrow(PeriodNotPurgeable::class);

    expect(File::withTrashed()->count())->toBe(1);
});

it('refuses a period inside the retention window', function () {
    config()->set('doccum.retention.purge_after_years', 7);
    archived(2024, 3);
    fileIn(2024, 3);

    expect(fn () => app(PeriodPurger::class)->purge(2024, 3))
        ->toThrow(PeriodNotPurgeable::class);

    expect(File::withTrashed()->count())->toBe(1);
});

it('refuses when a file in the period is under legal hold', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3);
    fileIn(2020, 3, ['legal_hold' => true]);

    expect(fn () => app(PeriodPurger::class)->purge(2020, 3))
        ->toThrow(PeriodNotPurgeable::class);

    // Nothing at all goes, not merely the held file: a partial purge of a
    // period under hold is exactly what a hold exists to prevent.
    expect(File::withTrashed()->count())->toBe(2);
});

it('names the blockers rather than just refusing', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3, ['legal_hold' => true, 'name' => 'Held.pdf']);

    $plan = app(PeriodPurger::class)->plan(2020, 3);

    expect($plan->purgeable)->toBeFalse()
        ->and($plan->blockers)->not->toBeEmpty()
        ->and(implode(' ', $plan->blockers))->toContain('legal hold');
});

it('reports what would go without touching anything', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3);
    fileIn(2020, 3);

    $plan = app(PeriodPurger::class)->plan(2020, 3);

    expect($plan->purgeable)->toBeTrue()
        ->and($plan->fileCount)->toBe(2)
        ->and($plan->byteCount)->toBe(200)
        ->and(File::withTrashed()->count())->toBe(2);
});

it('purges a period that passes every guard', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $file = fileIn(2020, 3);
    $keep = fileIn(2025, 3);

    $report = app(PeriodPurger::class)->purge(2020, 3);

    expect($report->fileCount)->toBe(1)
        ->and(File::withTrashed()->find($file->id))->toBeNull()
        ->and(File::withTrashed()->find($keep->id))->not->toBeNull()
        ->and(ArchivePeriod::where('year', 2020)->where('month', 3)->value('purged_at'))->not->toBeNull();
});

it('takes the versions, text, properties and search rows with it', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $file = fileIn(2020, 3);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);

    app(PeriodPurger::class)->purge(2020, 3);

    expect(FileVersion::where('file_id', $file->id)->count())->toBe(0)
        ->and(SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->count())->toBe(0);
});

it('leaves the directory structure standing', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3);

    app(PeriodPurger::class)->purge(2020, 3);

    expect(Directory::find($this->dir->id))->not->toBeNull();
});

it('deletes the objects it accounted for', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $file = fileIn(2020, 3);
    $version = FileVersion::factory()->for($file)->create([
        'version_number' => 1, 'object_key' => 'files/2020/03/'.$file->uuid.'/v1/a.txt',
    ]);
    Storage::disk('documents')->put($version->object_key, 'contents');

    app(PeriodPurger::class)->purge(2020, 3);

    // Rows without objects is a leak that only shows up as a storage bill.
    expect(Storage::disk('documents')->exists($version->object_key))->toBeFalse();
});
```

- [ ] **Step 2: Run and watch them fail**

- [ ] **Step 3: Implement**

`plan()` gathers counts and blockers without writing. `purge()` calls `plan()`,
throws `PeriodNotPurgeable` with the blockers unless `purgeable`, then deletes
inside a transaction: objects first through `DocumentStorage`, then rows, then
stamps `purged_at`.

- [ ] **Step 4: Prove each guard is load-bearing**

For each of the three guards — not archived, inside retention, legal hold —
remove it, confirm the matching test fails, restore it. Report that you did.

- [ ] **Step 5: Run focused, then full suite. Commit.**

---

### Task 4: The commands

**Files:**
- Create: `app/Console/Commands/ClosePeriods.php`, `PurgePeriod.php`, `PurgeExpired.php`
- Modify: `routes/console.php` (schedule)
- Test: `tests/Feature/Archive/ArchiveCommandsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\ArchivePeriod;
use App\Models\File;

it('closes finished periods on a schedule', function () {
    $this->travelTo('2026-05-10');
    File::factory()->create(['period_year' => 2026, 'period_month' => 3, 'size' => 10]);

    $this->artisan('doccum:close-periods')->assertSuccessful();

    expect(ArchivePeriod::where('year', 2026)->where('month', 3)->value('archived_at'))->not->toBeNull();
});

it('is a dry run unless told otherwise', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-period 2020 3')->assertSuccessful();

    // The default must never destroy anything: an operator reaching for this
    // command is usually finding out what it would do.
    expect(File::withTrashed()->count())->toBe(1);
});

it('purges when explicitly told to', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-period 2020 3 --force')->assertSuccessful();

    expect(File::withTrashed()->count())->toBe(0);
});

it('explains why it will not purge', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create([
        'period_year' => 2020, 'period_month' => 3, 'size' => 100, 'legal_hold' => true,
    ]);

    $this->artisan('doccum:purge-period 2020 3 --force')
        ->expectsOutputToContain('legal hold')
        ->assertFailed();

    expect(File::withTrashed()->count())->toBe(1);
});

it('does nothing on a schedule unless automatic purging is switched on', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.retention.purge_after_years', 1);
    config()->set('doccum.retention.auto_purge', false);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-expired')->assertSuccessful();

    // Reports candidates, deletes nothing. Scheduled deletion is opt-in
    // because nobody is watching when it runs.
    expect(File::withTrashed()->count())->toBe(1);
});

it('purges expired periods once switched on', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.retention.purge_after_years', 1);
    config()->set('doccum.retention.auto_purge', true);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-expired')->assertSuccessful();

    expect(File::withTrashed()->count())->toBe(0);
});
```

- [ ] **Step 2: Run, implement, run**

Schedule `doccum:close-periods` daily and `doccum:purge-expired` weekly in
`routes/console.php`. `purge-period` takes `--force` to act; without it, it
prints the plan.

- [ ] **Step 3: Commit.**

---

## Done when

- A finished period closes automatically, recording how many files and bytes it holds, counting trashed files because their objects still cost storage.
- An archived period accepts no new files or versions.
- Purging refuses anything unarchived, anything inside the retention window, and anything under legal hold — each proven by removing the guard and watching a test fail.
- A blocked purge names its blockers and changes nothing.
- `doccum:purge-period` reports by default and destroys only with `--force`.
- Scheduled purging is off unless explicitly enabled.
- Purging removes versions, text, properties, search rows and the objects themselves, and leaves the directory structure standing.
- `php artisan test` green; the container still boots clean from empty volumes.
