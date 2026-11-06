<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Job for the collision test of a worker that dies after its double check
 * passed. Every compute(), doubleCheck() and complete() run writes one row to
 * collision_events, so the number of real runs is a plain count across
 * processes.
 *
 * On its first attempt (retries still 0) complete() records its run and then
 * kills its own process with SIGKILL, so the step is left Running with the
 * double check already sealed. Any later attempt finishes normally.
 */
final class CollisionSealedTestJob extends BaseStepJob
{
    /** High budget so a recovery in the test never exhausts retries. */
    public int $tries = 1000;

    public int $retries = 1000;

    protected function compute(): mixed
    {
        $this->record('compute');

        return ['pid' => getmypid(), 'step_id' => $this->step->id];
    }

    public function doubleCheck(): bool
    {
        $this->record('doubleCheck');

        return true;
    }

    public function complete(): void
    {
        $this->record('complete');

        if ((int) $this->step->retries !== 0) {
            return;
        }

        if (! function_exists('posix_kill')) {
            throw new RuntimeException('posix_kill() is missing, so this worker cannot be killed.');
        }

        posix_kill(getmypid(), SIGKILL);

        // Unreachable when the signal is delivered.
        throw new RuntimeException('The worker survived SIGKILL.');
    }

    private function record(string $event): void
    {
        DB::table('collision_events')->insert([
            'step_id' => $this->step->id,
            'event' => $event,
            'pid' => getmypid(),
        ]);
    }
}
