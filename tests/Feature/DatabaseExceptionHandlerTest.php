<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use StepDispatcher\Abstracts\BaseDatabaseExceptionHandler;
use StepDispatcher\Support\DatabaseExceptionHandlers\GenericDatabaseExceptionHandler;
use StepDispatcher\Support\DatabaseExceptionHandlers\MySqlDatabaseExceptionHandler;
use StepDispatcher\Support\DatabaseExceptionHandlers\PgsqlDatabaseExceptionHandler;
use StepDispatcher\Support\DatabaseExceptionHandlers\SqliteDatabaseExceptionHandler;

/*
|--------------------------------------------------------------------------
| Database Exception Handlers
|--------------------------------------------------------------------------
|
| The job's exception funnel leans on these handlers to decide whether a
| QueryException is transient (retry), a code/schema bug (fail fast), or
| an idempotent duplicate (ignore). The classification is driven by
| per-driver message / SQLSTATE / errno tables, so the matchers and the
| factory routing are pinned here.
|
*/

/**
 * Build a QueryException whose getMessage() contains $message and, when
 * an errno is supplied, carries errorInfo[1] = $errno (only PDOExceptions
 * propagate errorInfo into the QueryException).
 */
function makeQueryException(string $message, ?int $errno = null, string $sqlState = 'HY000'): QueryException
{
    if ($errno !== null) {
        $previous = new PDOException($message, 0);
        $previous->errorInfo = [$sqlState, $errno, $message];
    } else {
        $previous = new RuntimeException($message);
    }

    return new QueryException('testing', 'select 1', [], $previous);
}

it('routes the make() factory to the correct handler per engine', function (): void {
    expect(BaseDatabaseExceptionHandler::make('mysql'))->toBeInstanceOf(MySqlDatabaseExceptionHandler::class)
        ->and(BaseDatabaseExceptionHandler::make('mariadb'))->toBeInstanceOf(MySqlDatabaseExceptionHandler::class)
        ->and(BaseDatabaseExceptionHandler::make('pgsql'))->toBeInstanceOf(PgsqlDatabaseExceptionHandler::class)
        ->and(BaseDatabaseExceptionHandler::make('sqlite'))->toBeInstanceOf(SqliteDatabaseExceptionHandler::class);
});

it('falls back to the generic handler for an unknown engine instead of throwing', function (): void {
    $handler = BaseDatabaseExceptionHandler::make('cockroach');

    expect($handler)->toBeInstanceOf(GenericDatabaseExceptionHandler::class)
        ->and($handler->getDatabaseEngine())->toBe('generic');
});

it('classifies a locked-database error as retryable on sqlite', function (): void {
    $handler = new SqliteDatabaseExceptionHandler;

    expect($handler->shouldRetry(makeQueryException('database is locked')))->toBeTrue()
        ->and($handler->isPermanentError(makeQueryException('database is locked')))->toBeFalse();
});

it('classifies a missing table / constraint error as permanent on sqlite', function (): void {
    $handler = new SqliteDatabaseExceptionHandler;

    expect($handler->isPermanentError(makeQueryException('no such table: steps')))->toBeTrue()
        ->and($handler->isPermanentError(makeQueryException('UNIQUE constraint failed: steps.id')))->toBeTrue()
        ->and($handler->shouldRetry(makeQueryException('no such table: steps')))->toBeFalse();
});

it('matches sqlite retry by errno when the message does not match', function (): void {
    $handler = new SqliteDatabaseExceptionHandler;

    // SQLITE_BUSY (5) — message itself carries no retryable phrase.
    expect($handler->shouldRetry(makeQueryException('opaque driver error', errno: 5)))->toBeTrue();
});

it('classifies a mysql deadlock as retryable by message and errno', function (): void {
    $handler = new MySqlDatabaseExceptionHandler;

    expect($handler->shouldRetry(makeQueryException('Deadlock found when trying to get lock')))->toBeTrue()
        ->and($handler->shouldRetry(makeQueryException('lock', errno: 1213)))->toBeTrue()
        ->and($handler->isPermanentError(makeQueryException('Duplicate entry')))->toBeTrue();
});

it('classifies a pgsql serialization failure as retryable by sqlstate', function (): void {
    $handler = new PgsqlDatabaseExceptionHandler;

    expect($handler->shouldRetry(makeQueryException('deadlock detected')))->toBeTrue()
        ->and($handler->isPermanentError(makeQueryException('duplicate key value violates unique constraint')))->toBeTrue();
});

it('retries our advisory-lock RuntimeException even though it is not a QueryException', function (): void {
    $handler = new SqliteDatabaseExceptionHandler;

    expect($handler->shouldRetry(new RuntimeException('Failed to acquire advisory lock for X')))->toBeTrue()
        ->and($handler->shouldRetry(new RuntimeException('some other runtime error')))->toBeFalse();
});

it('never classifies a non-QueryException as permanent or ignorable', function (): void {
    $handler = new SqliteDatabaseExceptionHandler;

    expect($handler->isPermanentError(new RuntimeException('plain error')))->toBeFalse()
        ->and($handler->shouldIgnore(new RuntimeException('plain error')))->toBeFalse();
});

it('classifies nothing on the generic handler', function (): void {
    $handler = new GenericDatabaseExceptionHandler;

    expect($handler->shouldRetry(makeQueryException('database is locked')))->toBeFalse()
        ->and($handler->isPermanentError(makeQueryException('no such table')))->toBeFalse()
        ->and($handler->shouldIgnore(makeQueryException('UNIQUE constraint failed')))->toBeFalse();
});

it('grows the backoff exponentially and caps it', function (): void {
    $handler = new SqliteDatabaseExceptionHandler; // base 1s, multiplier 2, cap 5s

    expect($handler->getBackoffSeconds(0))->toBe(1)
        ->and($handler->getBackoffSeconds(1))->toBe(2)
        ->and($handler->getBackoffSeconds(2))->toBe(4)
        ->and($handler->getBackoffSeconds(10))->toBe(5); // capped
});
