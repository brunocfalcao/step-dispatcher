<?php

declare(strict_types=1);

namespace StepDispatcher\Abstracts;

use Exception;
use Illuminate\Database\QueryException;
use StepDispatcher\Support\DatabaseExceptionHandlers\MySqlDatabaseExceptionHandler;
use StepDispatcher\Support\DatabaseExceptionHandlers\PgsqlDatabaseExceptionHandler;
use Throwable;

/**
 * BaseDatabaseExceptionHandler
 *
 * - Abstract base for handling database-specific exceptions in a unified way.
 * - Provides default helper methods that check properties defined in concrete handlers.
 * - Defines factory method `make()` to instantiate handler per database engine.
 * - Enables retries, ignores, or fail-fast logic for database errors.
 * - Used in BaseStepJob to decide database error handling and retry logic.
 */
abstract class BaseDatabaseExceptionHandler
{
    public int $backoffSeconds = 10;

    abstract public function ping(): bool;

    abstract public function getDatabaseEngine(): string;

    abstract public function shouldRetry(Throwable $exception): bool;

    abstract public function shouldIgnore(Throwable $exception): bool;

    abstract public function isPermanentError(Throwable $exception): bool;

    final public static function make(?string $engine = null): self
    {
        $engine ??= config('database.connections.'.config('database.default').'.driver');

        return match ($engine) {
            'mysql', 'mariadb' => new MySqlDatabaseExceptionHandler,
            'pgsql' => new PgsqlDatabaseExceptionHandler,
            default => throw new Exception("Unsupported database engine: {$engine}")
        };
    }

    final public function isRetryableError(QueryException $e): bool
    {
        return $this->matchesErrorPattern($e, 'retryableMessages', 'retryableSqlStates', 'retryableErrorCodes');
    }

    final public function isPermanentErrorPattern(QueryException $e): bool
    {
        return $this->matchesErrorPattern($e, 'permanentMessages', 'permanentSqlStates', 'permanentErrorCodes');
    }

    final public function isIgnorableError(QueryException $e): bool
    {
        return $this->matchesErrorPattern($e, 'ignorableMessages', 'ignorableSqlStates', 'ignorableErrorCodes');
    }

    private function matchesErrorPattern(
        QueryException $e,
        string $messagesProp,
        string $sqlStatesProp,
        string $errorCodesProp,
    ): bool {
        if (property_exists($this, $messagesProp)) {
            foreach ($this->$messagesProp as $pattern) {
                if (str_contains($e->getMessage(), $pattern)) {
                    return true;
                }
            }
        }

        if (property_exists($this, $sqlStatesProp)) {
            if (in_array($e->getCode(), $this->$sqlStatesProp, strict: true)) {
                return true;
            }
        }

        if (property_exists($this, $errorCodesProp)) {
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode && in_array($errorCode, $this->$errorCodesProp, strict: true)) {
                return true;
            }
        }

        return false;
    }

    public function getBackoffSeconds(int $retryAttempt): int
    {
        $multiplier = property_exists($this, 'backoffMultiplier') ? $this->backoffMultiplier : 2;
        $maxBackoff = property_exists($this, 'maxBackoffSeconds') ? $this->maxBackoffSeconds : 120;

        return min((int) ($this->backoffSeconds * ($multiplier ** $retryAttempt)), $maxBackoff);
    }
}
