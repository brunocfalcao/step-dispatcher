<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

/**
 * Test fixture: a parent job whose compute() spawns one child step into
 * the parent's child block — the canonical "parent stays Running until
 * children conclude" shape.
 */
final class SpawningParentTestJob extends BaseStepJob
{
    public int $retries = 1;

    protected function compute(): mixed
    {
        Step::create([
            'class' => PrefixCarryingTestJob::class,
            'block_uuid' => $this->step->child_block_uuid,
            'index' => 1,
            'type' => 'default',
            'queue' => 'default',
            'group' => $this->step->group,
            'state' => Pending::class,
        ]);

        return ['spawned' => 1];
    }
}
