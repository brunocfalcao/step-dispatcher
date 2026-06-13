<?php

declare(strict_types=1);

namespace StepDispatcher\Support\DatabaseExceptionHandlers;

use StepDispatcher\Abstracts\BaseDatabaseExceptionHandler;
use StepDispatcher\Concerns\DatabaseExceptionHelpers;

/**
 * Fallback handler for database engines without a tuned handler.
 *
 * Knows no engine-specific error patterns, so nothing is classified as
 * retryable/ignorable/permanent — database exceptions fall through to
 * the job's regular exception handling. The factory must never throw
 * for an unknown engine: that would make every job on that engine fail
 * inside prepareJobExecution() before compute() even runs.
 */
final class GenericDatabaseExceptionHandler extends BaseDatabaseExceptionHandler
{
    use DatabaseExceptionHelpers;

    public function ping(): bool
    {
        return true;
    }

    public function getDatabaseEngine(): string
    {
        return 'generic';
    }
}
