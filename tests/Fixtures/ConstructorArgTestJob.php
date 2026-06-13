<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for DispatchesJobs::instantiateJobWithArguments. The
 * constructor takes a required `value` with no default, so a test can
 * pin: arguments are mapped by name, a missing required argument raises
 * the descriptive InvalidArgumentException, and a supplied argument flows
 * through to compute().
 */
final class ConstructorArgTestJob extends BaseStepJob
{
    public int $retries = 1;

    public function __construct(public int $value)
    {
    }

    protected function compute(): mixed
    {
        return ['value' => $this->value];
    }
}
