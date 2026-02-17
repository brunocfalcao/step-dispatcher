<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Running;
use Spatie\ModelStates\Transition;

final class DispatchedToRunning extends Transition
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
        $this->step->hostname = gethostname();
        $this->step->started_at = now();
        $this->step->is_throttled = false; // Step is no longer waiting due to throttling
        $this->step->state = new Running($this->step);
        $this->step->save();

        return $this->step;
    }
}
