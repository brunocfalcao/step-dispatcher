<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture: a job declaring `$retries = 0`. Used to pin that the
 * stale-recovery watchdog never derives a zero retry budget from it —
 * zero would mean "fail on the very first stale detection without a
 * single recovery attempt".
 */
final class ZeroRetryTestJob extends BaseStepJob
{
    public int $retries = 0;

    protected function compute(): mixed
    {
        return ['ran' => true];
    }
}
