<?php

declare(strict_types=1);

namespace StepDispatcher\Support\DatabaseExceptionHandlers;

use StepDispatcher\Abstracts\BaseDatabaseExceptionHandler;
use StepDispatcher\Concerns\DatabaseExceptionHelpers;

final class SqliteDatabaseExceptionHandler extends BaseDatabaseExceptionHandler
{
    use DatabaseExceptionHelpers;

    public int $backoffSeconds = 1;

    protected array $retryableMessages = [
        'database is locked',
        'database table is locked',
    ];

    protected array $retryableSqlStates = [];

    protected array $retryableErrorCodes = [
        5,  // SQLITE_BUSY
        6,  // SQLITE_LOCKED
    ];

    protected array $permanentMessages = [
        'UNIQUE constraint failed',
        'NOT NULL constraint failed',
        'FOREIGN KEY constraint failed',
        'no such table',
        'no such column',
        'syntax error',
    ];

    protected array $permanentSqlStates = [];

    protected array $permanentErrorCodes = [];

    protected array $ignorableMessages = [];

    protected array $ignorableSqlStates = [];

    protected array $ignorableErrorCodes = [];

    protected int $backoffMultiplier = 2;

    protected int $maxBackoffSeconds = 5;

    public function ping(): bool
    {
        return true;
    }

    public function getDatabaseEngine(): string
    {
        return 'sqlite';
    }
}
