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
     * Every live + archive steps table in the current schema, any prefix.
     * Dispatcher/ticks/saturation tables don't carry step rows and are
     * excluded by the exact-suffix match.
     *
     * @return list<string>
     */
    private function triageTables(): array
    {
        $names = array_map(
            static fn (array $table): string => $table['name'],
            Schema::getTables(),
        );

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => (bool) preg_match('/^(?:\w+_)?steps(?:_archive)?$/', $name)
                && ! str_contains($name, 'dispatcher'),
        ));
    }
};
