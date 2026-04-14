<?php

declare(strict_types=1);

namespace StepDispatcher\Support\DatabaseExceptionHandlers;

use StepDispatcher\Abstracts\BaseDatabaseExceptionHandler;
use StepDispatcher\Concerns\DatabaseExceptionHelpers;

final class PgsqlDatabaseExceptionHandler extends BaseDatabaseExceptionHandler
{
    use DatabaseExceptionHelpers;

    protected array $retryableMessages = [
        'deadlock detected',
        'could not serialize access',
        'canceling statement due to lock timeout',
        'server closed the connection unexpectedly',
        'connection reset by peer',
        'SSL connection has been closed unexpectedly',
        'too many connections',
    ];

    protected array $retryableSqlStates = [
        '40001', // serialization_failure
        '40P01', // deadlock_detected
        '08006', // connection_failure
        '08001', // sqlclient_unable_to_establish_sqlconnection
        '08004', // sqlserver_rejected_establishment_of_sqlconnection
        '57P01', // admin_shutdown
        '57P02', // crash_shutdown
        '57P03', // cannot_connect_now
    ];

    protected array $retryableErrorCodes = [];

    protected array $permanentMessages = [
        'duplicate key value violates unique constraint',
        'null value in column',
        'value too long for type',
        'column does not exist',
        'relation does not exist',
        'syntax error',
        'invalid input syntax',
        'numeric field overflow',
    ];

    protected array $permanentSqlStates = [
        '23505', // unique_violation
        '23502', // not_null_violation
        '23503', // foreign_key_violation
        '42P01', // undefined_table
        '42703', // undefined_column
        '42601', // syntax_error
        '22003', // numeric_value_out_of_range
    ];

    protected array $permanentErrorCodes = [];

    protected array $ignorableMessages = [];

    protected array $ignorableSqlStates = [];

    protected array $ignorableErrorCodes = [];

    public int $backoffSeconds = 2;

    protected int $backoffMultiplier = 2;

    protected int $maxBackoffSeconds = 5;

    public function ping(): bool
    {
        return true;
    }

    public function getDatabaseEngine(): string
    {
        return 'pgsql';
    }
}
