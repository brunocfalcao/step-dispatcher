<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Running;
use StepDispatcher\States\Stopped;
use Spatie\ModelStates\Transition;

final class RunningToStopped extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        if (! ($this->step->state instanceof Running)) {
            return false;
        }

        return true;
    }

    public function handle(): Step
    {
        // Transition to Stopped state
        $this->step->state = new Stopped($this->step);
        $this->step->is_throttled = false; // Clear throttle flag - step is no longer waiting
        $this->step->save();

        return $this->step;
    }
}
