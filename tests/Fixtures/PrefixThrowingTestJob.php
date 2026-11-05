<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use RuntimeException;
use StepDispatcher\Abstracts\BaseStepJob;
use Throwable;

/**
 * Test fixture whose failure escapes handle(): compute() throws and the
 * resolver hook throws too, so the exception handler cannot contain it.
 */
final class PrefixThrowingTestJob extends BaseStepJob
{
    public int $retries = 1;

    public function resolveException(Throwable $e): void
    {
        throw new RuntimeException('resolver failed');
    }

    protected function compute(): mixed
    {
        throw new RuntimeException('compute failed');
    }
}
