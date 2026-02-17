<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use Spatie\ModelStates\Transition;

final class RunningToCompleted extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        return true;
    }

    public function handle(): Step
    {
        /**
         * Prevent parent steps from completing early.
         * Only allow transition if all child steps are concluded.
         */
        if ($this->step->isParent() && ! $this->step->childStepsAreConcluded()) {
            return $this->step;
        }

        $this->step->state = new Completed($this->step);
        $this->step->completed_at = now();
        $this->step->is_throttled = false; // Clear throttle flag - step is no longer waiting
        $this->step->save();

        return $this->step;
    }
}
