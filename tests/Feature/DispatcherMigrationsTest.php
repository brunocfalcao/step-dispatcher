<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function dispatcherMigration(string $file): object
{
    return require __DIR__.'/../../database/migrations/'.$file;
}

it('adds the exception triage columns to each prefixed steps set', function (string $prefix): void {
    $tables = [$prefix.'steps', $prefix.'steps_archive'];

    foreach ($tables as $table) {
        Schema::create($table, static function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->uuid('block_uuid')->nullable();
            $blueprint->string('state')->nullable();
            $blueprint->text('error_stack_trace')->nullable();
        });
    }

    dispatcherMigration('2026_07_09_000000_add_exception_triage_columns.php')->up();

    foreach ($tables as $table) {
        expect(Schema::hasColumn($table, 'exception_analysed'))->toBeTrue()
            ->and(Schema::hasColumn($table, 'exception_verdict'))->toBeTrue();
    }
})->with([
    'an ordinary prefix' => ['trading_'],
    'a prefix that is the word dispatcher' => ['dispatcher_'],
    'a prefix containing the word dispatcher' => ['my_dispatcher_'],
]);

it('leaves foreign tables and the dispatcher coordination table out of the triage upgrade', function (): void {
    Schema::create('wizard_steps', static function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('state')->nullable();
        $blueprint->text('error_stack_trace')->nullable();
    });

    dispatcherMigration('2026_07_09_000000_add_exception_triage_columns.php')->up();

    expect(Schema::hasColumn('wizard_steps', 'exception_analysed'))->toBeFalse()
        ->and(Schema::hasColumn('steps_dispatcher', 'exception_analysed'))->toBeFalse();
});

it('rolls the timestamp precision migration back on SQLite without running a MySQL statement', function (): void {
    $migration = dispatcherMigration('2026_01_31_233000_alter_steps_dispatcher_last_selected_at_to_timestamp6.php');

    $migration->up();
    $migration->down();

    expect(Schema::hasColumn('steps_dispatcher', 'last_selected_at'))->toBeTrue();
});
