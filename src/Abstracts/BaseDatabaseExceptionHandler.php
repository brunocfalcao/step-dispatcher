<?php

declare(strict_types=1);

namespace StepDispatcher\Abstracts;

use Exception;
use Illuminate\Database\QueryException;
use StepDispatcher\Support\DatabaseExceptionHandlers\MySqlDatabaseExceptionHandler;
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

    // Must be implemented by concrete handler
    abstract public function ping(): bool;

    // Returns the database engine name (e.g., 'mysql', 'postgresql')
    abstract public function getDatabaseEngine(): string;

    // Check if exception should trigger retry (provided via DatabaseExceptionHelpers trait)
    abstract public function shouldRetry(Throwable $exception): bool;

    // Check if exception should be ignored (provided via DatabaseExceptionHelpers trait)
    abstract public function shouldIgnore(Throwable $exception): bool;

    // Check if exception is permanent error (provided via DatabaseExceptionHelpers trait)
    abstract public function isPermanentError(Throwable $exception): bool;

    /**
     * Factory method - instantiate correct handler based on DB engine.
     * Auto-detects driver from config when no argument given.
     */
    final public static function make(?string $engine = null): self
    {
        $engine ??= config('database.connections.'.config('database.default').'.driver');

        return match ($engine) {
            'mysql', 'mariadb' => new MySqlDatabaseExceptionHandler,
            default => throw new Exception("Unsupported database engine: {$engine}")
        };
    }

    /**
     * Check if QueryException should be retried based on error patterns.
     * Checks properties defined in concrete handlers:
     * - $retryableMessages (array of error message patterns)
     * - $retryableSqlStates (array of SQLSTATE codes)
     * - $retryableErrorCodes (array of MySQL error numbers)
     */
    final public function isRetryableError(QueryException $e): bool
    {
        // Check message patterns
        if (property_exists($this, 'retryableMessages')) {
            foreach ($this->retryableMessages as $pattern) {
                if (! (str_contains(haystack: $e->getMessage(), needle: $pattern))) {
                    continue;
                }

                return true;
            }
        }

        // Check SQLSTATE codes
        if (property_exists($this, 'retryableSqlStates')) {
            if (in_array($e->getCode(), $this->retryableSqlStates, strict: true)) {
                return true;
            }
        }

        // Check MySQL error numbers (errorInfo[1])
        if (property_exists($this, 'retryableErrorCodes')) {
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode && in_array($errorCode, $this->retryableErrorCodes, strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if QueryException is a permanent error based on patterns.
     * Permanent errors should fail immediately without retry.
     * Checks properties: $permanentMessages, $permanentSqlStates, $permanentErrorCodes
     */
    final public function isPermanentErrorPattern(QueryException $e): bool
    {
        // Check permanent message patterns
        if (property_exists($this, 'permanentMessages')) {
            foreach ($this->permanentMessages as $pattern) {
                if (! (str_contains(haystack: $e->getMessage(), needle: $pattern))) {
                    continue;
                }

                return true;
            }
        }

        // Check permanent SQLSTATE codes
        if (property_exists($this, 'permanentSqlStates')) {
            if (in_array($e->getCode(), $this->permanentSqlStates, strict: true)) {
                return true;
            }
        }

        // Check permanent error codes
        if (property_exists($this, 'permanentErrorCodes')) {
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode && in_array($errorCode, $this->permanentErrorCodes, strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if QueryException should be ignored (complete successfully).
     * Checks properties: $ignorableMessages, $ignorableSqlStates, $ignorableErrorCodes
     */
    final public function isIgnorableError(QueryException $e): bool
    {
        // Check ignorable message patterns
        if (property_exists($this, 'ignorableMessages')) {
            foreach ($this->ignorableMessages as $pattern) {
                if (! (str_contains(haystack: $e->getMessage(), needle: $pattern))) {
                    continue;
                }

                return true;
            }
        }

        // Check ignorable SQLSTATE codes
        if (property_exists($this, 'ignorableSqlStates')) {
            if (in_array($e->getCode(), $this->ignorableSqlStates, strict: true)) {
                return true;
            }
        }

        // Check ignorable error codes
        if (property_exists($this, 'ignorableErrorCodes')) {
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode && in_array($errorCode, $this->ignorableErrorCodes, strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get backoff seconds with exponential increase based on retry attempt.
     * Uses properties: $backoffMultiplier, $maxBackoffSeconds
     */
    public function getBackoffSeconds(int $retryAttempt): int
    {
        $multiplier = property_exists($this, 'backoffMultiplier')
            ? $this->backoffMultiplier
            : 2;

        $maxBackoff = property_exists($this, 'maxBackoffSeconds')
            ? $this->maxBackoffSeconds
            : 120;

        $backoff = $this->backoffSeconds * ($multiplier ** $retryAttempt);

        return min((int) $backoff, $maxBackoff);
    }
}
