<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToRunning extends Transition
{
    public function __construct(
        private Step $step
    ) {}

    public function handle(): Step
    {
        $this->step->hostname = gethostname();
        $this->step->started_at = now();
        $this->step->is_throttled = false;
        $this->step->state = new Running($this->step);
        $this->step->save();

        Step::log($this->step->id, 'states', sprintf(
            'Pending → Running | hostname=%s',
            $this->step->hostname ?? 'unknown'
        ));

        return $this->step;
    }
}
