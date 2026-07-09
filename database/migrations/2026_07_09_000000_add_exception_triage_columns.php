<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exception-triage columns for the failures workflow: an operator marks a
 * failed step's exception as analysed (bulk-resolved per class from the
 * consumer's UI), and an AI-generated verdict can be persisted alongside the
 * failure for later re-reading.
 *
 * Prefixed table sets (e.g. `trading_steps`) are created by the installer —
 * which now includes these columns — but sets installed BEFORE this release
 * already exist, so this migration sweeps every steps/steps_archive table in
 * the schema, whatever its prefix, and adds the columns where missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->triageTables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'exception_analysed')) {
                    $blueprint->tinyInteger('exception_analysed')->default(0)->after('error_stack_trace');
                }
                if (! Schema::hasColumn($table, 'exception_verdict')) {
                    $blueprint->longText('exception_verdict')->nullable()->after('exception_analysed');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->triageTables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'exception_verdict')) {
                    $blueprint->dropColumn('exception_verdict');
                }
                if (Schema::hasColumn($table, 'exception_analysed')) {
                    $blueprint->dropColumn('exception_analysed');
                }
            });
        }
    }

    /**
     * Every live + archive steps table in the CURRENT database, any prefix.
     * Two traps this filter dodges (both hit in the wild on first run):
     * cross-schema leakage — getTables() can surface same-named tables from
     * sibling databases the connection user can see (an unrelated
     * `wizard_steps` in another local schema) — and foreign tables that
     * merely end in `_steps`. A table only qualifies when it exists in this
     * database AND carries the dispatcher's shape (block_uuid + state).
     *
     * @return list<string>
     */
    private function triageTables(): array
    {
        $database = Schema::getConnection()->getDatabaseName();

        $names = array_map(
            static fn (array $table): string => $table['name'],
            array_filter(
                Schema::getTables(),
                static fn (array $table): bool => ($table['schema'] ?? null) === null
                    || $table['schema'] === $database,
            ),
        );

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => (bool) preg_match('/^(?:\w+_)?steps(?:_archive)?$/', $name)
                && ! str_contains($name, 'dispatcher')
                && Schema::hasTable($name)
                && Schema::hasColumn($name, 'block_uuid')
                && Schema::hasColumn($name, 'state'),
        ));
    }
};
