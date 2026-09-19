<?php

declare(strict_types=1);

use App\Concerns\ProfileValidationRules;
use App\Support\NameKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Schema portability audit -- decision/0010
|--------------------------------------------------------------------------
|
| decision/0010: doccum states every semantic it relies on; none is
| inherited from a driver or framework default. Six defects (and one
| ledger decision, 0008) all trace back to that one pattern -- a semantic
| that was true only because of what a driver happened to default to,
| never written down anywhere. This file adds no behaviour. It makes the
| existing behaviour stated and asserted, so the next unstated inherited
| semantic fails here instead of in someone's install.
|
| Runs unchanged on every leg of the CI matrix (sqlite/mysql/pgsql x two
| PHP versions -- see .github/workflows/tests.yml). Where today's schema
| does not yet satisfy a rule this file checks, the gap is named in an
| exclusion list with a comment, rather than silently passing or being
| fixed inline -- this item audits, it does not repair.
*/

/**
 * InnoDB's key-length cap, and PostgreSQL's btree tuple-size cap, byte for
 * byte. SQLite has no such ceiling, so it is deliberately absent here --
 * the index-width assertions below simply do not run for it, though the
 * rest of this test (introspection, FK coverage, the site inventory, the
 * folding-rule list) still does.
 *
 * @var array<string, int>
 */
const SCHEMA_AUDIT_KEY_WIDTH_LIMITS = [
    'mysql' => 3072,
    'mariadb' => 3072,
    'pgsql' => 2704,
];

/**
 * Indexes whose worst-case width cannot be judged from declared column
 * length alone, because a driver-conditional raw expression built them
 * instead of a plain Blueprint column list. Each entry names why, and the
 * generic width check below skips exactly these and nothing else.
 *
 * @var array<string, array<string, string>>
 */
const SCHEMA_AUDIT_DRIVER_CONDITIONAL_INDEXES = [
    'properties' => [
        // database/migrations/2026_09_16_090100_create_properties_table.php
        // creates this with a raw DB::statement(), not a Blueprint index, so
        // each driver can be sized to its own key-length ceiling -- this
        // generic column-length check cannot see through a raw expression
        // to size it, on any of the three drivers below, which is why the
        // entry stays rather than being removed now that #39 (this item)
        // has landed for all of them:
        //   - MySQL/MariaDB index value_string(255), a 255-char prefix,
        //     safely under InnoDB's 3072-byte limit even at utf8mb4's worst
        //     case (issue #37/#38).
        //   - PostgreSQL indexes md5(value_string) instead of the column,
        //     a fixed 32-character hash safely under its ~2704-byte btree
        //     tuple limit regardless of how long value_string gets (issue
        //     #39; decision/0005). This serves ONLY an equality lookup
        //     written as `md5(value_string) = md5(?)` alongside the real
        //     `value_string = ?` predicate -- never ordering, never
        //     prefix/LIKE matching, and never a plain `value_string = ?` on
        //     its own, which Postgres's planner will not match to this
        //     index. tests/Feature/PropertyTest.php's "round-trips a
        //     value_string built from three-byte characters" is the
        //     regression test for the failure this replaced.
        //   - SQLite indexes value_string whole -- it has no such ceiling
        //     (see the note on SCHEMA_AUDIT_KEY_WIDTH_LIMITS above).
        'properties_property_definition_id_value_string_index' => 'Driver-conditional raw index on value_string -- MySQL/MariaDB gets a 255-char prefix, '.
            'PostgreSQL gets md5(value_string) (equality-only; issue #39/decision/0005), SQLite '.
            'indexes the column whole. See the migration\'s own comment for the full reasoning '.
            'behind each branch, including which queries the PostgreSQL branch can and cannot serve.',
    ],
];

/**
 * Foreign-key columns with no index leading with that column, on the legs
 * that do not require one (InnoDB does; SQLite and PostgreSQL do not --
 * see decision/0008).
 *
 * Empty as of item/fk-index-coverage (issue #65). Six columns were found
 * here while building this audit -- directories.created_by,
 * files.created_by, file_versions.uploaded_by, search_documents.owner_id
 * and settings.updated_by each gained an explicit index in their original
 * create-table migration (doccum's own tables, and nothing has been
 * released yet -- the same practice #38 and #69 already used);
 * role_has_permissions.role_id gained one via its own additive migration
 * instead (database/migrations/2026_09_17_120000_add_role_id_index_to_role_has_permissions_table.php),
 * because that table's create migration is a published copy of
 * spatie/laravel-permission's own and CLAUDE.md's upgrade seam forbids
 * editing a framework or package migration -- an upgrade would overwrite
 * it. None of the six needed dropping any existing index, so decision/0008's
 * create-before-drop rule was never in play here.
 *
 * Kept as a real (if empty) exclusion list, not deleted, so a future
 * regression has somewhere to be named again rather than a re-introduced
 * gap failing with no place to record why it is temporarily accepted.
 *
 * Keyed by "table.column" for a single-column foreign key.
 *
 * @var array<string, string>
 */
const SCHEMA_AUDIT_FK_INDEX_EXCLUSIONS = [];

/**
 * Every raw-SQL or collation-dependent site under app/ and database/, found
 * by grepping for the patterns below and reviewed one by one while building
 * this audit -- not trusted from the issue that named four of them, which
 * said as much. Each entry records why the site is (or, for the false
 * positives the same grep catches, is not) driver-dependent. A new hit that
 * is not a key here fails the scan test below; a key with no matching hit
 * any more is stale and fails it too, so this cannot silently rot.
 *
 * @var array<string, string>
 */
const SCHEMA_AUDIT_DRIVER_DEPENDENT_SITES = [
    'app/Search/Fts5SearchIndex.php' => "SQLite's FTS5 MATCH operator and bm25() ranking function, both SQLite-only -- ".
        'DoccumServiceProvider only resolves this class when getDriverName() === \'sqlite\'. '.
        'Raw DB::statement()/DB::select() because FTS5 virtual tables are not something the '.
        'query builder can target.',
    'app/Search/LikeSearchIndex.php' => "PostgreSQL's LIKE is case-sensitive; SQLite's and MySQL's default utf8mb4 collation are ".
        'not. Switches to ILIKE on pgsql specifically so search does not go silently empty there -- '.
        'the fix for issue #2/PR #2.',
    'app/Search/Terms.php' => 'Builds an FTS5 MATCH expression, which is SQLite-only syntax: quoted terms, and a '.
        'trailing * for the prefix queries that let "invo" find "invoice". Only Fts5SearchIndex '.
        'calls toFts5(), and DoccumServiceProvider resolves that class only when getDriverName() '.
        'is sqlite. words() is plain PHP and is what LikeSearchIndex uses, so the two drivers '.
        'share the tokenising and differ only in the expression built from it -- which is the '.
        'whole point of keeping them in one class. Worth knowing that the two are not equivalent: '.
        'LIKE matches %word% and so finds a word from its middle, while a prefix finds it only '.
        'from its start.',
    'app/Search/SearchIndex.php' => 'The word "LIKE" appears only in a docblock explaining LikeSearchIndex\'s relationship to '.
        'this interface -- prose, not a query. Not actually driver-dependent; registered because '.
        'the grep cannot tell the difference.',
    'app/Actions/Fortify/AuthenticateUser.php' => 'No raw SQL and no LIKE. The grep matches mb_strtolower(), which is PHP\'s own '.
        'case folding rather than the database\'s -- and that is the point: the identifier is '.
        'folded IN PHP before the lookup, so neither the username nor the email comparison '.
        'depends on a column collation that differs across the three drivers. PostgreSQL would '.
        'compare case-sensitively where SQLite and MySQL would not; folding first makes the '.
        'question never arise. See item/email-folding (issue #59) and item/login-by-username '.
        '(issue #75).',
    'app/Models/User.php' => 'Same shape, one layer down: booted() folds email through EmailKey and the username '.
        'through mb_strtolower() on every save, so the stored value is already folded and no '.
        'comparison anywhere relies on a driver\'s collation to match case. The grep matches the '.
        'PHP function, not a query. The username hook also raises UsernameWouldBeAmbiguous on an '.
        '@ -- not a portability concern, but it lives in the same closure, so a reader arriving '.
        'here from this inventory should know why.',
    'app/Actions/Directories/MoveDirectory.php' => 'Two raw DB::statement() calls rewriting `path` and recomputing `depth` with REPLACE() and '.
        'LENGTH() -- called out in the surrounding comment as "the one expression that behaves '.
        'identically on SQLite, MySQL, and Postgres\", so this is raw SQL by choice, not a raw SQL '.
        'that happens to only work on one driver.',
    'app/Actions/Directories/RestoreDirectory.php' => "A 'like' prefix match against `path`, which is a materialised path of ids and '/' only -- ".
        "no letters ever appear in it, so LikeSearchIndex's case-sensitivity split does not apply ".
        'here. Registered because the operator is still raw and driver-conditional in general; '.
        'safe today only because of what this column happens to contain.',
    'app/Models/Directory.php' => "Same 'like' prefix match against `path` (descendants(), scopeInSubtreeOf()) as "
        .'RestoreDirectory, same reasoning: safe because path is ids and slashes only.',
    'app/Actions/Directories/PurgeSubtreeOrphans.php' => "A 'like' prefix match against `path`, resolving the subtree whose objects a "
        .'force-delete is about to strand (issue #86). Same "path is ids and slashes only" '
        .'reasoning as Directory, RestoreDirectory and DirectoryAccess; registered because the '
        .'operator is raw and driver-conditional in general, not because this call site is at '
        .'risk. The match runs through withTrashed() deliberately -- the cascade it gets ahead '
        .'of does not consult the SoftDeletes scope, so neither may this.',
    'app/Services/DirectoryAccess.php' => "resolveViewable()'s 'like'/'not like' prefix matches against `path`, expanding a granted ".
        'subtree and excluding a trashed one (issue #49). Same "path is ids only" reasoning as '.
        'Directory and RestoreDirectory -- named explicitly in this item\'s brief as a known site.',
    'app/Services/DocumentStorage.php' => 'strtolower() in servesPresignedUrls(), folding a URL HOST before comparing it '.
        "against the loopback names -- hostnames are case-insensitive by RFC, and '127.0.0.1' has ".
        'no case at all. Nothing here touches a database: the value comes from '.
        'filesystems.disks.*.endpoint and is compared in PHP. Registered for the same reason '.
        'SearchIndex.php is, one entry below -- the grep cannot tell a folding rule about a '.
        'hostname from one about a column. See issue #74.',
    'app/Support/NameKey.php' => 'mb_strtolower() here is not a driver dependency -- it is the fix for one. It states the '.
        "folding rule (NFC-normalise, then lowercase) in PHP so no driver's own collation gets to ".
        "decide what \"the same name\" means; see issue #46 and this file's own docblock. See the ".
        'folding-rule inventory below.',
    'app/Support/EmailKey.php' => 'Same reasoning as NameKey.php, one entry up: mb_strtolower() (plus its own docblock, which '.
        "mentions SQLite's LOWER() by name to explain why it is not used) states email's folding ".
        "rule in PHP instead of a driver's collation deciding it -- see issue #59 and this file's ".
        'own docblock. See the folding-rule inventory below.',
    'app/Console/Commands/ConfigShow.php' => 'strtolower() here folds a config *key path* (e.g. "storage.KEY") before checking whether '.
        'it should be masked as a secret -- pure PHP string matching, nothing stored, compared for '.
        'uniqueness, or touching a database. Registered as a false positive the grep cannot filter '.
        'out on its own.',
    'app/Support/OpenApi/OpenApiSpecBuilder.php' => 'strtolower() here folds an HTTP METHOD NAME -- "GET", "POST" -- into the lowercase key '.
        'OpenAPI requires for a path item\'s operations. The value comes from the router\'s own '.
        'methods() array, never from user input, and is written into docs/api/openapi.json; no '.
        'database, no column, no collation is involved anywhere in this class, which holds no '.
        'Illuminate import at all. False positive, registered rather than filtered: narrowing the '.
        'grep\'s `lower\\(` to a word boundary would stop matching strtolower() everywhere, and six '.
        'registered sites -- AuthenticateUser, ConfigShow, User, DocumentStorage, SearchIndexer and '.
        'LedgerValidator -- are caught by exactly that, so the "fix" would silently disarm them. '.
        'Checked by running both patterns over app/ and database/ before choosing: 20 files matched '.
        'to 13.',
    'app/Services/SearchIndexer.php' => 'strtolower() here normalises a file *extension* for the search projection\'s `extension` '.
        'column -- a value the application computes and writes, not one compared against '.
        'unnormalised user input, and no database function is involved. False positive.',
    'app/Providers/FortifyServiceProvider.php' => 'Str::lower() here folds the login rate-limiter\'s throttle key (an in-memory/cache lookup '.
        'key, not a database column) so "Alice" and "alice" share one bucket. Unrelated to schema '.
        'or SQL. False positive.',
    'app/Support/LedgerValidator.php' => "mb_strtolower() here reproduces GitHub's own heading-to-anchor slug rule for validating ".
        'docs/ledger cross-references -- dev tooling with no database involved at all. False '.
        'positive.',
    'database/migrations/2026_09_16_090100_create_properties_table.php' => 'The driver-conditional raw CREATE INDEX for value_string, described above in '.
        'SCHEMA_AUDIT_DRIVER_CONDITIONAL_INDEXES -- the reason doccum could not be installed on '.
        'MySQL at all before this was added (issues #37/#38).',
    'database/migrations/2026_09_16_110000_create_search_index_table.php' => "Creates SQLite's FTS5 virtual table with a raw DB::statement(), guarded by ".
        "getDriverName() !== 'sqlite' returning early -- would fail the migration outright on ".
        'every other driver if it ran unconditionally.',
];

/**
 * Every column compared against user input for equality or uniqueness,
 * with the folding rule it relies on -- or the honest absence of one.
 * decision/0010's third rule: any such comparison states its folding rule
 * in code rather than borrowing whatever the column's default collation
 * happens to do, and that rule differs by driver exactly when nothing
 * states it.
 *
 * @var array<string, string>
 */
const SCHEMA_AUDIT_FOLDING_RULES = [
    'users.email' => 'Folded by App\Support\EmailKey::of(): trim, then mb_strtolower() -- case only, '.
        'deliberately not NameKey\'s NFC-normalise/accent-sensitive rule, which has no report '.
        'behind it for email. Lowercase-on-write, straight into `email` itself (unlike names, no '.
        'separate _key column: nothing needs email\'s original casing preserved for display), via '.
        "User's saving hook, which nothing can bypass. Rule::unique() in "
        .'App\Concerns\ProfileValidationRules::emailRules() still compares the submitted value '.
        'verbatim, so CreateNewUser, FirstRun::submit() and Profile::updateProfileInformation() '.
        'each fold the submitted value before validating too. Login resolves through the same '.
        'rule via Fortify::authenticateUsing() (App\Actions\Fortify\AuthenticateUser), stated '.
        "explicitly rather than left to depend on config('fortify.lowercase_usernames') -- see "
        .'that config file\'s own comment. Fixed by issue #59; covered by tests/Unit/EmailKeyTest.php '.
        'and tests/Feature/Auth/RegistrationTest.php, AuthenticationTest.php, '.
        'Settings/ProfileUpdateTest.php and FirstRunSetupTest.php. Pre-existing rows written before '.
        'this fix keep whatever case they already had until their next save -- deliberately not '.
        'backfilled here; same choice item/name-key made for name_key on existing rows, and for '.
        'the same reason: a bulk fold risks colliding two already-mis-folded rows, which is a '.
        'reconciliation decision, not a mechanical one. See this item\'s report for the argument.',
    'users.username' => 'App\Concerns\ProfileValidationRules::usernameRules() constrains input to '.
        '/^[a-z0-9._-]+$/ before Rule::unique() ever runs, so no two valid usernames can differ '.
        'only by case or accent -- the column\'s own collation is never asked to fold anything, on '.
        'any driver. Asserted below directly against that regex.',
    'settings.key' => 'Not user input. Every caller of App\Services\Settings::set()/get() passes a literal '.
        "dotted key it wrote itself in source (e.g. 'auth.default_role', 'storage.provider') -- ".
        'never a value an end user typed. The unique constraint on `settings.key` guards against '.
        'an application bug writing the wrong literal, not against two differently-cased user '.
        'inputs colliding, so there is no folding rule to state.',
    'property_definitions.key' => 'App\Livewire\Admin\PropertyDefinitions runs Str::slug($this->key, \'_\') before '.
        'validating, so the value that ever reaches the `regex:/^[a-z0-9_]+$/` rule and '.
        "Rule::unique('property_definitions', 'key') is already lowercase ASCII -- covered by ".
        'tests/Feature/PropertyDefinitionAdminTest.php\'s "normalises a key to the allowed '.
        'character set".',
    'directories.name / files.name' => 'Folded by App\Support\NameKey::of(): NFC-normalise, then mb_strtolower(), persisted in '.
        '`name_key` and compared there (Directory::scopeWhereNamed(), File\'s equivalent) instead '.
        'of on `name` directly -- deliberately never `LOWER(name) = ?`, which is exactly the '.
        'collation-dependent comparison issue #46 found. Already done, and already covered by '.
        'tests/Unit/NameKeyTest.php.',
];

/**
 * Recursively scans every .php file under the given path (relative to
 * base_path()) for the driver-dependent-site pattern, pure PHP -- no
 * external `grep`, per CLAUDE.md's "no test may require an external
 * binary".
 *
 * @return array<string, list<int>> relative file path => matching line numbers
 */
function schemaAuditScanDriverDependentSites(string $relativeDirectory): array
{
    $pattern = "~DB::statement|DB::select|whereRaw|selectRaw|orderByRaw|->raw\(|'like'|LIKE|LOWER\(|lower\(~";

    $root = base_path($relativeDirectory);
    $hits = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = $relativeDirectory.substr($file->getPathname(), strlen($root));
        $relativePath = str_replace('\\', '/', $relativePath);

        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $number => $line) {
            if (preg_match($pattern, $line) === 1) {
                $hits[$relativePath][] = $number + 1;
            }
        }
    }

    ksort($hits);

    return $hits;
}

/**
 * A conservative, deliberately coarse worst-case byte width for one column
 * inside an index key. Variable-length text is sized from its declared
 * length x4 (utf8mb4's worst case, per this item's brief); anything
 * unbounded (text/blob/json, no declared length to size against) is
 * treated as unmeasurable rather than guessed at, so a future index on one
 * of those fails loudly instead of silently passing. Every other column
 * type in this schema is fixed-width and nowhere near either limit, so a
 * flat, generous stand-in is used rather than modelling storage byte-for-
 * byte.
 */
function schemaAuditColumnWidth(array $column): int
{
    $typeName = strtolower((string) ($column['type_name'] ?? ''));
    $type = strtolower((string) ($column['type'] ?? ''));

    if (str_contains($typeName, 'char')) {
        if (preg_match('/\((\d+)\)/', $type, $matches) === 1) {
            return ((int) $matches[1]) * 4;
        }

        return PHP_INT_MAX;
    }

    if (str_contains($typeName, 'text') || str_contains($typeName, 'blob') || str_contains($typeName, 'json')) {
        return PHP_INT_MAX;
    }

    return 8;
}

it('sizes every index against utf8mb4\'s worst case, or names the driver-conditional expression that is not generically sizeable', function () {
    $driver = DB::connection()->getDriverName();
    $limit = SCHEMA_AUDIT_KEY_WIDTH_LIMITS[$driver] ?? null;

    if ($limit === null) {
        // SQLite imposes no index key length limit, so there is no ceiling
        // here to size anything against -- and its implicit
        // sqlite_autoindex_* entries are artefacts it generates for primary
        // key and unique constraints, not indexes doccum declares or could
        // shorten. Asserting width on this driver measures nothing.
        //
        // Named explicitly rather than skipped by absence, so a driver added
        // to the matrix without a registered limit fails here instead of
        // quietly opting out of the check.
        expect($driver)->toBe(
            'sqlite',
            "Driver {$driver} has no entry in SCHEMA_AUDIT_KEY_WIDTH_LIMITS. Add its index ".
            'key length ceiling, or record here why it has none.',
        );

        return;
    }

    foreach (Schema::getTables() as $tableInfo) {
        $table = $tableInfo['name'];
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $allowListed = SCHEMA_AUDIT_DRIVER_CONDITIONAL_INDEXES[$table] ?? [];

        foreach (Schema::getIndexes($table) as $index) {
            if (array_key_exists($index['name'], $allowListed)) {
                continue;
            }

            $width = 0;

            foreach ($index['columns'] as $columnName) {
                $column = $columns->get($columnName);

                expect($column)->not->toBeNull(
                    "Index {$table}.{$index['name']} references unknown column {$columnName}.",
                );

                $width += schemaAuditColumnWidth($column);
            }

            expect($width)->toBeLessThan(
                PHP_INT_MAX,
                "Index {$table}.{$index['name']} covers an unbounded column (text/blob/json) with ".
                'no declared length to size against. Either add a driver-conditional expression to '.
                'SCHEMA_AUDIT_DRIVER_CONDITIONAL_INDEXES with the reasoning, or size the index '.
                'explicitly (a prefix, a functional index, a shorter column).',
            );

            if ($limit !== null) {
                expect($width)->toBeLessThanOrEqual(
                    $limit,
                    "Index {$table}.{$index['name']} has a worst-case key width of {$width} bytes ".
                    "on {$driver}, over its {$limit}-byte limit, and is not in ".
                    'SCHEMA_AUDIT_DRIVER_CONDITIONAL_INDEXES.',
                );
            }
        }
    }
});

it('keeps a leading index on every foreign key column, on every leg -- decision/0008, generalised', function () {
    foreach (Schema::getTables() as $tableInfo) {
        $table = $tableInfo['name'];
        $indexes = Schema::getIndexes($table);

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $columns = $foreignKey['columns'];
            $key = $table.'.'.implode(',', $columns);

            if (array_key_exists($key, SCHEMA_AUDIT_FK_INDEX_EXCLUSIONS)) {
                continue;
            }

            $hasLeadingIndex = collect($indexes)->contains(
                fn (array $index): bool => array_slice($index['columns'], 0, count($columns)) === $columns,
            );

            expect($hasLeadingIndex)->toBeTrue(
                "Foreign key {$key} has no index whose first column(s) are its own -- decision/0008's ".
                'errno 1553 rule. Either keep/add one, or register a named exclusion in '.
                'SCHEMA_AUDIT_FK_INDEX_EXCLUSIONS explaining why it is safe to go without.',
            );
        }
    }
});

it('inventories every raw-SQL or collation-dependent site under app/ and database/, and fails on an unregistered one', function () {
    $hits = array_merge(
        schemaAuditScanDriverDependentSites('app'),
        schemaAuditScanDriverDependentSites('database'),
    );

    $unregistered = array_values(array_diff(array_keys($hits), array_keys(SCHEMA_AUDIT_DRIVER_DEPENDENT_SITES)));

    expect($unregistered)->toBe(
        [],
        'Found driver-dependent site(s) not in SCHEMA_AUDIT_DRIVER_DEPENDENT_SITES: '.
        implode(', ', $unregistered).'. Register each with the driver semantics it relies on.',
    );

    $stale = array_values(array_diff(array_keys(SCHEMA_AUDIT_DRIVER_DEPENDENT_SITES), array_keys($hits)));

    expect($stale)->toBe(
        [],
        'SCHEMA_AUDIT_DRIVER_DEPENDENT_SITES lists site(s) that no longer match the scan: '.
        implode(', ', $stale).'. Remove the stale entry.',
    );
});

it('states the folding rule (or the honest lack of one) for every column compared against user input', function () {
    expect(SCHEMA_AUDIT_FOLDING_RULES)->toHaveKeys([
        'users.email',
        'users.username',
        'settings.key',
        'property_definitions.key',
        'directories.name / files.name',
    ]);
});

it('folds a username to a character set no case or accent can vary within, before uniqueness is ever checked', function () {
    $rules = (new class
    {
        use ProfileValidationRules;

        /** @return array<int, mixed> */
        public function rules(): array
        {
            return $this->usernameRules();
        }
    })->rules();

    $regexRule = collect($rules)->first(
        fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'regex:'),
    );

    expect($regexRule)->not->toBeNull();

    $pattern = substr($regexRule, strlen('regex:'));

    expect(preg_match($pattern, 'johndoe'))->toBe(1)
        ->and(preg_match($pattern, 'John.Doe-9_x'))->toBe(0)
        ->and(preg_match($pattern, 'JOHNDOE'))->toBe(0)
        ->and(preg_match($pattern, "jos\u{00E9}"))->toBe(0);
});

it('folds directory and file names via name_key, never via the column collation -- see tests/Unit/NameKeyTest.php for the full behaviour', function () {
    expect(NameKey::of('Report.PDF'))->toBe(NameKey::of('report.pdf'))
        ->and(NameKey::of("r\u{00E9}sum\u{00E9}.pdf"))->not->toBe(NameKey::of('resume.pdf'));
});
