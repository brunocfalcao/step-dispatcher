<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use Spatie\ModelStates\Transition;

final class RunningToPending extends Transition
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
        // Conditionally increment retry count based on is_throttled flag
        if (! $this->step->is_throttled) {
            $this->step->increment('retries');
        }

        // Reset timers
        $this->step->started_at = null;
        $this->step->completed_at = null;
        $this->step->duration = 0;

        // Reset the state to Pending
        $this->step->state = new Pending($this->step);
        $this->step->save();

        return $this->step;
    }
}
