<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for the `rotateToQueue` primitive. Exposes the trait
 * method as a public hook so feature tests can drive it without going
 * through the full job lifecycle pipeline.
 */
final class RotatableTestJob extends BaseStepJob
{
    public int $retries = 5;

    protected function compute(): mixed
    {
        return null;
    }

    public function callRotateToQueue(string $queueName): void
    {
        $this->rotateToQueue($queueName);
    }
}
