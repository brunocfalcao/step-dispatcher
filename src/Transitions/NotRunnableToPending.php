<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use Spatie\ModelStates\Transition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

final class NotRunnableToPending extends Transition
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
        $this->step->state = new Pending($this->step);
        $this->step->save();

        Step::log($this->step->id, 'states', 'NotRunnable → Pending');

        return $this->step;
    }
}
