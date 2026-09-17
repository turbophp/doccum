<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission's own migration
 * (database/migrations/2026_09_15_134326_create_permission_tables.php) gives
 * role_has_permissions a composite primary key (permission_id, role_id) that
 * leads with permission_id -- role_id, itself a foreign key to roles, is
 * never the leading column of any index there.
 *
 * That migration is a published copy of the package's own, so it is treated
 * the same as a framework migration under CLAUDE.md's upgrade seam: never
 * edited, because a package upgrade would overwrite it. The fix is this
 * additive migration instead.
 *
 * Guarded on both ends so it is safe to run twice and safe on MySQL/MariaDB,
 * where InnoDB already auto-created an index to support the role_id foreign
 * key (under a name of its own choosing, not this one) the moment the table
 * itself was created. Adding a second index that also leads with role_id
 * there would only be redundant, never wrong, but this checks for any
 * existing index leading with the column -- not merely one of this name --
 * before creating one, so it never attempts a duplicate.
 *
 * See tests/Feature/SchemaPortabilityTest.php's
 * SCHEMA_AUDIT_FK_INDEX_EXCLUSIONS (decision/0008) and issue #65.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->tableName();
        $column = $this->columnName();

        if ($this->hasLeadingIndex($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->index($column, 'role_has_permissions_role_id_index');
        });
    }

    public function down(): void
    {
        $table = $this->tableName();

        if (! Schema::hasTable($table)) {
            return;
        }

        $hasNamedIndex = collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => $index['name'] === 'role_has_permissions_role_id_index',
        );

        if (! $hasNamedIndex) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropIndex('role_has_permissions_role_id_index');
        });
    }

    /**
     * Mirrors the fallback the package's own migration uses, so this still
     * targets the right table if config/permission.php ever renames it.
     */
    private function tableName(): string
    {
        return config('permission.table_names')['role_has_permissions'] ?? 'role_has_permissions';
    }

    /**
     * Mirrors the fallback the package's own migration uses for the pivot
     * column name.
     */
    private function columnName(): string
    {
        return config('permission.column_names')['role_pivot_key'] ?? 'role_id';
    }

    private function hasLeadingIndex(string $table, string $column): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['columns'][0] ?? null) === $column,
        );
    }
};
