<?php

declare(strict_types=1);

namespace StepDispatcher\Support\DatabaseExceptionHandlers;

use StepDispatcher\Abstracts\BaseDatabaseExceptionHandler;
use StepDispatcher\Concerns\DatabaseExceptionHelpers;

/**
 * MySqlDatabaseExceptionHandler
 *
 * Focus:
 * - MySQL-specific error code and message patterns
 * - Classifies errors as: retryable (transient), permanent (code bugs), or ignorable
 * - Uses properties (not config) for error patterns - consistent with API handler pattern
 * - Provides exponential backoff configuration for transient errors
 */
final class MySqlDatabaseExceptionHandler extends BaseDatabaseExceptionHandler
{
    use DatabaseExceptionHelpers;

    /**
     * Retryable - transient database errors that may succeed on retry.
     *
     * These are temporary failures caused by:
     * - Resource contention (deadlocks, lock timeouts)
     * - Connection issues (server gone away, connection lost)
     * - Capacity limits (too many connections)
     */
    protected array $retryableMessages = [
        'Deadlock found when trying to get lock',
        'Lock wait timeout exceeded',
        'Too many connections',
        'MySQL server has gone away',
        'Error while sending QUERY packet',
        'Lost connection to MySQL server',
        'Connection timed out',
        'Server shutdown in progress',
    ];

    /**
     * SQLSTATE codes for retryable errors.
     *
     * 40001 = Serialization failure (deadlock)
     * HY000 = General error (often connection/server issues)
     * 08S01 = Communication link failure
     */
    protected array $retryableSqlStates = [
        '40001',
        'HY000',
        '08S01',
    ];

    /**
     * MySQL error numbers for retryable errors.
     *
     * 1213 = ER_LOCK_DEADLOCK
     * 1205 = ER_LOCK_WAIT_TIMEOUT
     * 1040 = ER_CON_COUNT_ERROR (too many connections)
     * 2006 = CR_SERVER_GONE_ERROR
     * 2013 = CR_SERVER_LOST
     * 1317 = ER_QUERY_INTERRUPTED
     */
    protected array $retryableErrorCodes = [
        1213,
        1205,
        1040,
        2006,
        2013,
        1317,
    ];

    /**
     * Permanent - errors indicating code bugs or schema issues.
     * These will NOT resolve with retry and should fail immediately.
     *
     * Common causes:
     * - Syntax errors in SQL
     * - Unknown columns/tables
     * - Data type mismatches
     * - Constraint violations
     */
    protected array $permanentMessages = [
        'Duplicate entry',
        'Data too long for column',
        'Unknown column',
        'Syntax error',
        "Table doesn't exist",
        'Unknown database',
        'Column cannot be null',
        'Out of range value',
        'Incorrect string value',
        'Truncated incorrect',
    ];

    /**
     * SQLSTATE codes for permanent errors.
     *
     * 23000 = Integrity constraint violation
     * 42S02 = Table not found
     * 42000 = Syntax error or access violation
     * 42S22 = Column not found
     * 22003 = Numeric value out of range
     */
    protected array $permanentSqlStates = [
        '23000',
        '42S02',
        '42000',
        '42S22',
        '22003',
    ];

    /**
     * MySQL error numbers for permanent errors.
     *
     * 1062 = ER_DUP_ENTRY
     * 1054 = ER_BAD_FIELD_ERROR
     * 1146 = ER_NO_SUCH_TABLE
     * 1064 = ER_PARSE_ERROR
     * 1406 = ER_DATA_TOO_LONG
     * 1048 = ER_BAD_NULL_ERROR
     */
    protected array $permanentErrorCodes = [
        1062,
        1054,
        1146,
        1064,
        1406,
        1048,
    ];

    /**
     * Ignorable - errors that should complete successfully despite occurring.
     *
     * Use very sparingly. Most errors should either retry or fail.
     * Empty by default - override in specific jobs if needed.
     */
    protected array $ignorableMessages = [];

    protected array $ignorableSqlStates = [];

    protected array $ignorableErrorCodes = [];

    /**
     * Exponential backoff configuration.
     *
     * backoffMultiplier: Each retry waits multiplier^attempt longer
     * maxBackoffSeconds: Cap on backoff time (prevents extremely long waits)
     */
    protected int $backoffMultiplier = 2;

    protected int $maxBackoffSeconds = 5;

    public function __construct()
    {
        // Base backoff for first retry (2s, 4s, then capped at 5s)
        $this->backoffSeconds = 2;
    }

    /**
     * Health check - verifies handler is properly instantiated.
     */
    public function ping(): bool
    {
        return true;
    }

    /**
     * Returns database engine identifier.
     */
    public function getDatabaseEngine(): string
    {
        return 'mysql';
    }
}
