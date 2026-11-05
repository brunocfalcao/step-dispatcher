<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use Spatie\ModelStates\Transition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

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
        // Conditionally increment retry count based on is_throttled flag. It is
        // written by the same save as the state: a save that gets refused then
        // leaves no raised count behind for the job to read as another attempt.
        if (! $this->step->is_throttled) {
            $this->step->retries = (int) $this->step->retries + 1;
        }

        // Reset timers
        $this->step->started_at = null;
        $this->step->completed_at = null;
        $this->step->duration = 0;

        // Reset the state to Pending
        $this->step->state = new Pending($this->step);
        $this->step->save();

        Step::log($this->step->id, 'states', sprintf(
            'Running → Pending | retries=%d | is_throttled=%s | dispatch_after=%s',
            (int) $this->step->retries,
            $this->step->is_throttled ? 'true' : 'false',
            $this->step->dispatch_after ? $this->step->dispatch_after->format('H:i:s.u') : 'null'
        ));

        return $this->step;
    }
}
