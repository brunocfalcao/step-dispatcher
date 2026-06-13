<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for result-storage semantics: compute() returns whatever
 * the test pinned on the static property, so tests can assert how falsy
 * (0, false, '', []) and null results are persisted to step.response.
 */
final class FalsyResultTestJob extends BaseStepJob
{
    public static mixed $result = null;

    public int $retries = 1;

    protected function compute(): mixed
    {
        return static::$result;
    }
}
