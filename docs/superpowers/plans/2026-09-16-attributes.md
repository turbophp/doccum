# doccum Attributes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Governed, typed metadata on directories and files — admin-defined attributes with real data types, validated on write, ready for the search projection to flatten.

**Architecture:** An `AttributeDefinition` declares a key, a data type and what it applies to. An `Attribute` stores one value for one definition on one directory or file, in a column matching the type — so numeric ranges and date ordering work in SQL rather than by string comparison. A single enum owns the mapping from data type to column, cast and validation rules, so adding a type is one change in one place.

**Tech Stack:** Laravel 13, Livewire 4, Flux UI, Pest 5.

**Spec:** `docs/superpowers/specs/2026-09-15-doccum-design.md` §4 (attribute_definitions, attributes), §5 (access control), §10 (surface)

**Previous plans:** foundation, access-control, storage, installer, embedded-storage — all merged.

## Global Constraints

- **Never edit anything under `vendor/`;** never edit a framework migration.
- `declare(strict_types=1);` on every PHP file authored, migrations included.
- Models declare fillability with `#[Fillable([...])]` — this codebase's idiom.
- Registrations go in `app/Providers/DoccumServiceProvider.php`; middleware in `bootstrap/app.php`.
- Nothing outside `App\Services\DocumentStorage` resolves a disk (`ConnectionProbe` excepted).
- **Never pass an interface to `toThrow()`** — Pest branches on `class_exists()`, false for interfaces, silently degrading to a substring match on the message.
- **Every write path authorises through a Policy.** Actions do not authorise; callers do, and a test must prove a caller without access is refused.
- Pest for all tests; each task ends with a green FULL suite and its own commit.
- Baseline entering this plan: **255 tests, 549 assertions**.

---

## File Structure

| Path | Responsibility |
|---|---|
| `app/Enums/AttributeDataType.php` | The single source of truth mapping a type to its column, cast and rules. |
| `app/Models/AttributeDefinition.php` | What an attribute is: key, label, type, options, applies-to. |
| `app/Models/Attribute.php` | One value for one definition on one directory or file. |
| `app/Actions/Attributes/SetAttributes.php` | Validates and writes a set of values for one subject. |
| `app/Policies/AttributeDefinitionPolicy.php` | Who may manage definitions. |
| `app/Livewire/Admin/AttributeDefinitions.php` | Definition CRUD. |
| `app/Livewire/Files/AttributePanel.php` | Editing values on a directory or file. |

---

### Task 1: The data type enum

**Files:**
- Create: `app/Enums/AttributeDataType.php`
- Test: `tests/Unit/AttributeDataTypeTest.php`

**Interfaces:**
- `AttributeDataType` — `String_`, `Text`, `Number`, `Date`, `Boolean`, `Select`
- `column(): string` — which value column stores it
- `rules(AttributeDefinition $definition): array` — validation for a value of this type
- `cast(mixed $value): mixed` — normalise an input to what the column stores
- `label(): string`

Adding a type later is one change here rather than a hunt through actions,
models and views.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AttributeDataType;

it('maps every type to a value column', function () {
    expect(AttributeDataType::String_->column())->toBe('value_string')
        ->and(AttributeDataType::Text->column())->toBe('value_string')
        ->and(AttributeDataType::Select->column())->toBe('value_string')
        ->and(AttributeDataType::Number->column())->toBe('value_number')
        ->and(AttributeDataType::Date->column())->toBe('value_date')
        ->and(AttributeDataType::Boolean->column())->toBe('value_boolean');
});

it('covers every case, so a new type cannot be forgotten', function () {
    foreach (AttributeDataType::cases() as $type) {
        expect($type->column())->toBeString()->not->toBeEmpty()
            ->and($type->label())->toBeString()->not->toBeEmpty();
    }
});

it('normalises a number', function () {
    expect(AttributeDataType::Number->cast('12.50'))->toBe(12.5)
        ->and(AttributeDataType::Number->cast(''))->toBeNull();
});

it('normalises a boolean', function () {
    expect(AttributeDataType::Boolean->cast('1'))->toBeTrue()
        ->and(AttributeDataType::Boolean->cast('0'))->toBeFalse()
        ->and(AttributeDataType::Boolean->cast(''))->toBeNull();
});

it('normalises a date to a storable string', function () {
    expect(AttributeDataType::Date->cast('2024-03-17'))->toBe('2024-03-17')
        ->and(AttributeDataType::Date->cast(''))->toBeNull();
});

it('trims a string and treats blank as absent', function () {
    expect(AttributeDataType::String_->cast('  hello  '))->toBe('hello')
        ->and(AttributeDataType::String_->cast('   '))->toBeNull();
});
```

Blank meaning "absent" rather than "empty string" matters: a cleared field must
remove the attribute, not store an empty value that then satisfies a required
rule.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\AttributeDefinition;
use Illuminate\Validation\Rule;

/**
 * The one place a data type's column, cast and validation live.
 *
 * Typed columns rather than a single stringly-typed value, so numeric ranges
 * and date ordering are SQL comparisons instead of string comparisons. See
 * spec §4.
 */
enum AttributeDataType: string
{
    case String_ = 'string';
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';

    public function column(): string
    {
        return match ($this) {
            self::String_, self::Text, self::Select => 'value_string',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_boolean',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::String_ => 'Text (single line)',
            self::Text => 'Text (multi-line)',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Boolean => 'Yes / no',
            self::Select => 'Choice from a list',
        };
    }

    /**
     * An empty input means the attribute is absent, not that it holds an empty
     * value -- otherwise clearing a field would still satisfy a required rule.
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($this) {
            self::String_, self::Text, self::Select => trim((string) $value),
            self::Number => (float) $value,
            self::Date => \Carbon\Carbon::parse((string) $value)->toDateString(),
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL),
        };
    }

    /** @return array<int, mixed> */
    public function rules(AttributeDefinition $definition): array
    {
        $rules = $definition->is_required ? ['required'] : ['nullable'];

        return [...$rules, ...match ($this) {
            self::String_ => ['string', 'max:1024'],
            self::Text => ['string', 'max:65535'],
            self::Number => ['numeric'],
            self::Date => ['date'],
            self::Boolean => ['boolean'],
            self::Select => ['string', Rule::in($definition->options ?? [])],
        }];
    }
}
```

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 2: Attribute definitions

**Files:**
- Create: migration, `app/Models/AttributeDefinition.php`, `database/factories/AttributeDefinitionFactory.php`
- Test: `tests/Feature/AttributeDefinitionTest.php`

**Interfaces:**
- `AttributeDefinition` with `key`, `label`, `data_type`, `options`, `is_required`, `applies_to`, `sort_order`
- `scopeFor(Builder, string $type)` — definitions applying to `directory` or `file`
- `AppliesTo` enum: `Directory`, `File`, `Both`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AppliesTo;
use App\Enums\AttributeDataType;
use App\Models\AttributeDefinition;
use Illuminate\Database\QueryException;

it('stores a definition with its type', function () {
    $definition = AttributeDefinition::create([
        'key' => 'invoice_no',
        'label' => 'Invoice number',
        'data_type' => AttributeDataType::String_,
        'applies_to' => AppliesTo::File,
    ]);

    expect($definition->fresh()->data_type)->toBe(AttributeDataType::String_)
        ->and($definition->fresh()->applies_to)->toBe(AppliesTo::File)
        ->and($definition->fresh()->is_required)->toBeFalse();
});

it('refuses a duplicate key', function () {
    AttributeDefinition::factory()->create(['key' => 'invoice_no']);

    expect(fn () => AttributeDefinition::factory()->create(['key' => 'invoice_no']))
        ->toThrow(QueryException::class);
});

it('stores select options as a list', function () {
    $definition = AttributeDefinition::factory()->create([
        'data_type' => AttributeDataType::Select,
        'options' => ['draft', 'final', 'signed'],
    ]);

    expect($definition->fresh()->options)->toBe(['draft', 'final', 'signed']);
});

it('scopes definitions to what they apply to', function () {
    AttributeDefinition::factory()->create(['key' => 'a', 'applies_to' => AppliesTo::File]);
    AttributeDefinition::factory()->create(['key' => 'b', 'applies_to' => AppliesTo::Directory]);
    AttributeDefinition::factory()->create(['key' => 'c', 'applies_to' => AppliesTo::Both]);

    expect(AttributeDefinition::query()->for('file')->pluck('key')->all())->toEqualCanonicalizing(['a', 'c'])
        ->and(AttributeDefinition::query()->for('directory')->pluck('key')->all())->toEqualCanonicalizing(['b', 'c']);
});

it('orders by sort order then label', function () {
    AttributeDefinition::factory()->create(['key' => 'z', 'label' => 'Zebra', 'sort_order' => 0]);
    AttributeDefinition::factory()->create(['key' => 'a', 'label' => 'Apple', 'sort_order' => 10]);

    expect(AttributeDefinition::query()->ordered()->pluck('key')->all())->toBe(['z', 'a']);
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the `AppliesTo` enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AppliesTo: string
{
    case Directory = 'directory';
    case File = 'file';
    case Both = 'both';

    public function includes(string $subject): bool
    {
        return $this === self::Both || $this->value === $subject;
    }
}
```

- [ ] **Step 4: Write the migration**

```php
Schema::create('attribute_definitions', function (Blueprint $table) {
    $table->id();
    $table->string('key', 64)->unique();
    $table->string('label', 191);
    $table->string('data_type', 16);
    $table->json('options')->nullable();
    $table->boolean('is_required')->default(false);
    $table->string('applies_to', 16)->default('both');
    $table->unsignedInteger('sort_order')->default(0);
    $table->timestamps();

    $table->index(['applies_to', 'sort_order']);
});
```

- [ ] **Step 5: Write the model and factory**

Cast `data_type` to `AttributeDataType`, `applies_to` to `AppliesTo`, `options`
to `array`, `is_required` to `boolean`. Mirror the database defaults in
`$attributes` so a new instance reports `false` rather than `null` for
`is_required` — the same trap `File` hit with `legal_hold`.

Scopes:

```php
    public function scopeFor(Builder $query, string $subject): Builder
    {
        return $query->whereIn('applies_to', [$subject, AppliesTo::Both->value]);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }
```

- [ ] **Step 6: Run focused, then full suite. Commit.**

---

### Task 3: Attribute values

**Files:**
- Create: migration, `app/Models/Attribute.php`, `database/factories/AttributeFactory.php`
- Modify: `app/Models/Directory.php`, `app/Models/File.php` (relations), `DoccumServiceProvider` (morph map entries already exist for `directory` and `file`)
- Test: `tests/Feature/AttributeTest.php`

**Interfaces:**
- `Attribute` with `attribute_definition_id`, morph `attributable`, and the four typed value columns
- `Attribute::$value` — reads and writes the column its definition dictates
- `Directory::attributes()` / `File::attributes()`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\AttributeDataType;
use App\Models\Attribute;
use App\Models\AttributeDefinition;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores a string in the string column', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();

    $attribute = Attribute::for($file, $definition)->setValue('ACME-001');

    expect($attribute->fresh()->value)->toBe('ACME-001')
        ->and(DB::table('attributes')->value('value_string'))->toBe('ACME-001')
        ->and(DB::table('attributes')->value('value_number'))->toBeNull();
});

it('stores a number in the number column so ranges work in sql', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::Number]);
    $file = File::factory()->create();

    Attribute::for($file, $definition)->setValue('1500.75');

    expect(DB::table('attributes')->value('value_number'))->toEqual(1500.75)
        ->and(Attribute::query()->where('value_number', '>', 1000)->count())->toBe(1)
        ->and(Attribute::query()->where('value_number', '>', 2000)->count())->toBe(0);
});

it('stores a date in the date column so ordering works', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::Date]);

    Attribute::for(File::factory()->create(), $definition)->setValue('2024-03-17');
    Attribute::for(File::factory()->create(), $definition)->setValue('2023-01-05');

    expect(Attribute::query()->orderBy('value_date')->pluck('value_date')->first())
        ->toStartWith('2023-01-05');
});

it('stores a boolean', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::Boolean]);

    $attribute = Attribute::for(File::factory()->create(), $definition)->setValue('1');

    expect($attribute->fresh()->value)->toBeTrue();
});

it('attaches to a directory as well as a file', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $directory = Directory::factory()->create();

    Attribute::for($directory, $definition)->setValue('archived');

    expect($directory->fresh()->attributes()->count())->toBe(1)
        ->and(DB::table('attributes')->value('attributable_type'))->toBe('directory');
});

it('holds one value per definition per subject', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();

    Attribute::for($file, $definition)->setValue('first');

    expect(fn () => Attribute::create([
        'attribute_definition_id' => $definition->id,
        'attributable_type' => 'file',
        'attributable_id' => $file->id,
        'value_string' => 'second',
    ]))->toThrow(QueryException::class);
});

it('overwrites rather than duplicating', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();

    Attribute::for($file, $definition)->setValue('first');
    Attribute::for($file, $definition)->setValue('second');

    expect(Attribute::count())->toBe(1)
        ->and(Attribute::first()->value)->toBe('second');
});

it('goes away with its file', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();
    Attribute::for($file, $definition)->setValue('x');

    $file->forceDelete();

    expect(Attribute::count())->toBe(0);
});
```

The "ranges work in SQL" and "ordering works" tests are the point of the typed
columns; if they ever pass against a single string column by accident, the
design has quietly been lost.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the migration**

```php
Schema::create('attributes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('attribute_definition_id')->constrained()->cascadeOnDelete();
    $table->string('attributable_type', 32);
    $table->unsignedBigInteger('attributable_id');
    $table->string('value_string', 1024)->nullable();
    $table->decimal('value_number', 20, 6)->nullable();
    $table->date('value_date')->nullable();
    $table->boolean('value_boolean')->nullable();
    $table->timestamps();

    $table->unique(['attribute_definition_id', 'attributable_type', 'attributable_id'], 'attributes_unique_per_subject');
    $table->index(['attributable_type', 'attributable_id']);
    $table->index(['attribute_definition_id', 'value_string']);
    $table->index(['attribute_definition_id', 'value_number']);
    $table->index(['attribute_definition_id', 'value_date']);
});
```

- [ ] **Step 4: Write the model**

```php
    /** Resolve, or prepare, the single attribute for this subject and definition. */
    public static function for(Model $subject, AttributeDefinition $definition): self
    {
        return static::firstOrNew([
            'attribute_definition_id' => $definition->getKey(),
            'attributable_type' => $subject->getMorphClass(),
            'attributable_id' => $subject->getKey(),
        ]);
    }

    /** Write into whichever column this definition's type dictates. */
    public function setValue(mixed $value): self
    {
        $type = $this->definition->data_type;

        // Clear every value column first: a definition's type can be changed by
        // an admin, and a stale value in the old column would keep being read
        // by anything that looks at the column directly, such as search.
        $this->forceFill([
            'value_string' => null,
            'value_number' => null,
            'value_date' => null,
            'value_boolean' => null,
            $type->column() => $type->cast($value),
        ])->save();

        return $this;
    }

    public function getValueAttribute(): mixed
    {
        return $this->{$this->definition->data_type->column()};
    }
```

- [ ] **Step 5: Add the relations**

`Directory` and `File` each gain:

```php
    public function attributes(): MorphMany
    {
        return $this->morphMany(Attribute::class, 'attributable');
    }
```

Deleting a file must remove its attributes. Eloquent's `morphMany` does not
cascade in the database, and the morph columns cannot carry a foreign key, so
add a `forceDeleted` hook on both models that deletes their attributes — there
is a test for it.

- [ ] **Step 6: Run focused, then full suite. Commit.**

---

### Task 4: Writing values safely

**Files:**
- Create: `app/Actions/Attributes/SetAttributes.php`, `app/Exceptions/UnknownAttribute.php`
- Test: `tests/Feature/SetAttributesTest.php`

**Interfaces:**
- `SetAttributes::handle(Model $subject, array $values): void` — keys are definition keys

Validation is driven by the definitions, so a required attribute cannot be
skipped and a select cannot hold a value outside its options, whatever the UI
sends.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\Attributes\SetAttributes;
use App\Enums\AppliesTo;
use App\Enums\AttributeDataType;
use App\Exceptions\UnknownAttribute;
use App\Models\Attribute;
use App\Models\AttributeDefinition;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->file = File::factory()->create();
    $this->invoice = AttributeDefinition::factory()->create([
        'key' => 'invoice_no', 'data_type' => AttributeDataType::String_, 'applies_to' => AppliesTo::File,
    ]);
    $this->amount = AttributeDefinition::factory()->create([
        'key' => 'amount', 'data_type' => AttributeDataType::Number, 'applies_to' => AppliesTo::File,
    ]);
    $this->status = AttributeDefinition::factory()->create([
        'key' => 'status', 'data_type' => AttributeDataType::Select,
        'options' => ['draft', 'final'], 'applies_to' => AppliesTo::File,
    ]);
});

it('writes several values at once', function () {
    app(SetAttributes::class)->handle($this->file, [
        'invoice_no' => 'ACME-001',
        'amount' => '1500.75',
        'status' => 'final',
    ]);

    expect(Attribute::count())->toBe(3)
        ->and($this->file->attributes()->count())->toBe(3);
});

it('rejects a select value outside its options', function () {
    expect(fn () => app(SetAttributes::class)->handle($this->file, ['status' => 'shredded']))
        ->toThrow(ValidationException::class);

    expect(Attribute::count())->toBe(0);
});

it('rejects a non-numeric number', function () {
    expect(fn () => app(SetAttributes::class)->handle($this->file, ['amount' => 'lots']))
        ->toThrow(ValidationException::class);
});

it('rejects an unknown key rather than ignoring it', function () {
    expect(fn () => app(SetAttributes::class)->handle($this->file, ['nonsense' => 'x']))
        ->toThrow(UnknownAttribute::class);
});

it('refuses a definition that does not apply to this subject', function () {
    $directoryOnly = AttributeDefinition::factory()->create([
        'key' => 'retention', 'data_type' => AttributeDataType::String_, 'applies_to' => AppliesTo::Directory,
    ]);

    expect(fn () => app(SetAttributes::class)->handle($this->file, ['retention' => '7y']))
        ->toThrow(UnknownAttribute::class);

    // ...and accepts it on the subject it does apply to.
    app(SetAttributes::class)->handle(Directory::factory()->create(), ['retention' => '7y']);
    expect(Attribute::count())->toBe(1);
});

it('enforces a required attribute', function () {
    $this->invoice->update(['is_required' => true]);

    expect(fn () => app(SetAttributes::class)->handle($this->file, ['invoice_no' => '']))
        ->toThrow(ValidationException::class);
});

it('removes an attribute when its value is cleared', function () {
    app(SetAttributes::class)->handle($this->file, ['invoice_no' => 'ACME-001']);
    expect(Attribute::count())->toBe(1);

    app(SetAttributes::class)->handle($this->file, ['invoice_no' => '']);

    expect(Attribute::count())->toBe(0);
});

it('writes nothing at all when one value is invalid', function () {
    expect(fn () => app(SetAttributes::class)->handle($this->file, [
        'invoice_no' => 'ACME-001',
        'amount' => 'lots',
    ]))->toThrow(ValidationException::class);

    // A partial write would leave the subject in a state the operator never
    // asked for and did not see.
    expect(Attribute::count())->toBe(0);
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the exception and action**

Validate every value against its definition's rules first, collecting all
errors, then write inside a transaction so the all-or-nothing test holds.
Clearing a value deletes the attribute rather than storing null, so "has this
attribute" is a row's existence rather than a column check.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 5: Managing definitions and editing values

**Files:**
- Create: `app/Policies/AttributeDefinitionPolicy.php`, `app/Livewire/Admin/AttributeDefinitions.php` + view, `app/Livewire/Files/AttributePanel.php` + view
- Modify: `routes/web.php`, `DoccumServiceProvider`, the browser view
- Test: `tests/Feature/AttributeDefinitionAdminTest.php`, `tests/Feature/AttributePanelTest.php`

**Interfaces:**
- Route `admin.attributes` → definition CRUD, gated by the `attributes.manage` permission
- `AttributePanel` mounted with a directory or file, gated by `update` on that subject

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Livewire\Admin\AttributeDefinitions;
use App\Models\AttributeDefinition;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->member = User::factory()->create();
    $this->member->assignRole('member');
});

it('lets an admin create a definition', function () {
    Livewire::actingAs($this->admin)
        ->test(AttributeDefinitions::class)
        ->set('key', 'invoice_no')
        ->set('label', 'Invoice number')
        ->set('data_type', 'string')
        ->call('save')
        ->assertHasNoErrors();

    expect(AttributeDefinition::where('key', 'invoice_no')->exists())->toBeTrue();
});

it('refuses a member', function () {
    Livewire::actingAs($this->member)
        ->test(AttributeDefinitions::class)
        ->assertForbidden();
});

it('rejects a duplicate key with a validation error, not an exception', function () {
    AttributeDefinition::factory()->create(['key' => 'invoice_no']);

    Livewire::actingAs($this->admin)
        ->test(AttributeDefinitions::class)
        ->set('key', 'invoice_no')
        ->set('label', 'Invoice number')
        ->set('data_type', 'string')
        ->call('save')
        ->assertHasErrors('key');
});

it('requires options for a select', function () {
    Livewire::actingAs($this->admin)
        ->test(AttributeDefinitions::class)
        ->set('key', 'status')
        ->set('label', 'Status')
        ->set('data_type', 'select')
        ->set('options_text', '')
        ->call('save')
        ->assertHasErrors('options_text');
});

it('normalises a key to the allowed character set', function () {
    Livewire::actingAs($this->admin)
        ->test(AttributeDefinitions::class)
        ->set('key', 'Invoice Number!')
        ->set('label', 'Invoice number')
        ->set('data_type', 'string')
        ->call('save')
        ->assertHasNoErrors();

    expect(AttributeDefinition::first()->key)->toBe('invoice_number');
});
```

And for the panel:

```php
<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\AttributeDataType;
use App\Livewire\Files\AttributePanel;
use App\Models\AttributeDefinition;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->file = File::factory()->for($this->dir, 'directory')->create();
    $this->definition = AttributeDefinition::factory()->create([
        'key' => 'invoice_no', 'data_type' => AttributeDataType::String_,
    ]);
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

function allowOn(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id, 'grantee_type' => 'user',
        'grantee_id' => $user->id, 'level' => $level,
    ]);
}

it('refuses someone with no access to the file', function () {
    Livewire::actingAs($this->user)
        ->test(AttributePanel::class, ['subject' => $this->file])
        ->assertForbidden();
});

it('refuses editing with only view access', function () {
    allowOn($this->dir, $this->user, AccessLevel::View);

    Livewire::actingAs($this->user)
        ->test(AttributePanel::class, ['subject' => $this->file])
        ->set('values.invoice_no', 'ACME-001')
        ->call('save')
        ->assertForbidden();
});

it('saves with edit access', function () {
    allowOn($this->dir, $this->user, AccessLevel::Edit);

    Livewire::actingAs($this->user)
        ->test(AttributePanel::class, ['subject' => $this->file])
        ->set('values.invoice_no', 'ACME-001')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->file->attributes()->count())->toBe(1);
});

it('shows only definitions that apply to the subject', function () {
    AttributeDefinition::factory()->create([
        'key' => 'retention', 'label' => 'Retention period',
        'data_type' => AttributeDataType::String_, 'applies_to' => App\Enums\AppliesTo::Directory,
    ]);
    allowOn($this->dir, $this->user, AccessLevel::Edit);

    Livewire::actingAs($this->user)
        ->test(AttributePanel::class, ['subject' => $this->file])
        ->assertSee('invoice_no')
        ->assertDontSee('Retention period');
});
```

- [ ] **Step 2: Run and watch them fail**

- [ ] **Step 3: Write the policy**

`AttributeDefinitionPolicy` gates everything on the `attributes.manage`
permission. Register it in `DoccumServiceProvider::boot()` alongside the others.

- [ ] **Step 4: Write the admin component**

Full-page Livewire via `Route::livewire()` and `#[Layout('layouts::app')]` —
this project's idiom. `mount()` authorises `viewAny`. Keys are slugified to
`[a-z0-9_]`; select options are entered one per line and split on save. Any
select that a definition-type change would strand is why `Attribute::setValue`
clears all four columns.

- [ ] **Step 5: Write the attribute panel**

Mounted with a `Directory` or `File`. `mount()` authorises `view` on the
subject; `save()` authorises `update` and then delegates to `SetAttributes`,
turning `UnknownAttribute` and `ValidationException` into field errors. Render
one control per applicable definition, chosen by data type.

- [ ] **Step 6: Show it in the browser**

Add the panel to the file browser's detail area for the selected item.

- [ ] **Step 7: Run focused, then the full suite. Commit.**

---

## Done when

- An admin defines attributes with real types; a member cannot.
- Values land in the column their type dictates, so a number range and a date sort are SQL comparisons.
- A value outside a select's options, a non-numeric number, a missing required value, or a definition that does not apply to the subject are all refused — and an invalid set writes nothing at all.
- Clearing a value removes the attribute rather than storing an empty one.
- Editing requires `edit` on the containing directory; viewing requires `view`.
- `php artisan test` green; the container still boots clean from empty volumes.
