<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;
use StepDispatcher\Support\RuntimeContext;

/**
 * Test fixture used by `PrefixWorkerPayloadTest`. Records the
 * ambient prefix that was active at the moment compute() ran so
 * the test can pin: did handle() restore stepPrefix onto the
 * RuntimeContext stack BEFORE the first DB read inside
 * prepareJobExecution?
 *
 * Lives in tests/Fixtures rather than tests/Feature so the
 * autoload path matches the StepDispatcher\Tests\ root.
 */
final class PrefixCarryingTestJob extends BaseStepJob
{
    public ?string $observedPrefix = null;

    public int $retries = 1;

    protected function compute(): mixed
    {
        $this->observedPrefix = app(RuntimeContext::class)->current();

        return ['observed_prefix' => $this->observedPrefix];
    }
}
