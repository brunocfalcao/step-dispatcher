<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * DatabaseExceptionHelpers (generic)
 *
 * - Database-agnostic utilities for classifying/handling database exceptions.
 * - Works with properties defined in concrete handlers (MySqlDatabaseExceptionHandler, etc.)
 * - Provides default implementations that delegate to base class pattern-matching methods.
 */
trait DatabaseExceptionHelpers
{
    /**
     * Check if exception should trigger retry.
     *
     * Retryable errors include:
     * - Deadlocks
     * - Lock wait timeouts
     * - Connection failures
     * - Advisory lock timeouts (RuntimeException)
     */
    public function shouldRetry(Throwable $exception): bool
    {
        // Handle advisory lock timeouts (our custom RuntimeException from upsert logic)
        if ($exception instanceof RuntimeException
            && str_contains(haystack: $exception->getMessage(), needle: 'Failed to acquire advisory lock')) {
            return true;
        }

        // Handle database query exceptions (deadlocks, connection errors, etc.)
        if ($exception instanceof QueryException) {
            return $this->isRetryableError($exception);
        }

        return false;
    }

    /**
     * Check if exception should be ignored (complete successfully despite error).
     *
     * Use sparingly - most errors should either retry or fail.
     * Examples: duplicate entry in idempotent operations, expected constraint violations.
     */
    public function shouldIgnore(Throwable $exception): bool
    {
        if (! ($exception instanceof QueryException)) {
            return false;
        }

        return $this->isIgnorableError($exception);
    }

    /**
     * Check if error is permanent (should fail immediately, no retry).
     *
     * Permanent errors include:
     * - Syntax errors
     * - Unknown columns/tables
     * - Constraint violations (when not ignorable)
     * - Data type mismatches
     *
     * These indicate code bugs or schema issues that won't resolve with retry.
     */
    public function isPermanentError(Throwable $exception): bool
    {
        if (! ($exception instanceof QueryException)) {
            return false;
        }

        return $this->isPermanentErrorPattern($exception);
    }
}
