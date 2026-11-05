<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;
use StepDispatcher\States\Failed;

/**
 * The job every workflow scenario runs. Each step's arguments choose how it
 * ends, so a scenario describes its outcome in data: stop, skip, fail, a
 * custom result, or should_start_or_retry => false to keep it retrying.
 */
final class ScenarioTestJob extends BaseStepJob
{
    public int $tries = 10;

    public int $retries = 10;

    protected function compute(): mixed
    {
        $arguments = $this->scenarioArguments();

        if ($arguments['stop'] ?? false) {
            $this->stopJob();

            return null;
        }

        if ($arguments['skip'] ?? false) {
            $this->skipJob();

            return null;
        }

        if ($arguments['fail'] ?? false) {
            $this->step->state->transitionTo(Failed::class);
            $this->stepStatusUpdated = true;

            return null;
        }

        return $arguments['custom_result'] ?? ['success' => true];
    }

    protected function shouldStartOrRetry(): bool
    {
        return (bool) ($this->scenarioArguments()['should_start_or_retry'] ?? parent::shouldStartOrRetry());
    }

    /**
     * @return array<string, mixed>
     */
    private function scenarioArguments(): array
    {
        $arguments = $this->step->arguments;

        return is_array($arguments) ? $arguments : [];
    }
}
