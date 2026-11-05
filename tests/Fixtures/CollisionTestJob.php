<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Job for the multi-process collision tests. Every run of compute() writes one
 * row to collision_runs, so the number of real executions of a step is a plain
 * count that works across processes.
 *
 * A step argument named wait_for_marker makes compute() announce itself by creating
 * the marker path with the suffix .entered, then hold until the marker file exists. The obsolete-worker scenario uses it to keep an attempt alive while
 * recovery runs.
 *
 * A step argument named skip_on_start makes the job's start check ask to skip.
 * With wait_for_marker as well, the same hold happens there instead, so a test
 * can act between the worker's start and its skip.
 */
final class CollisionTestJob extends BaseStepJob
{
    /** High budget so repeated recoveries in one test never exhaust retries. */
    public int $tries = 1000;

    public int $retries = 1000;

    public const ENTERED_SUFFIX = '.entered';

    private const MARKER_WAIT_SECONDS = 20;

    protected function startOrSkip(): bool
    {
        if (! ($this->step->arguments['skip_on_start'] ?? false)) {
            return true;
        }

        $this->holdAtMarker();

        return false;
    }

    protected function compute(): mixed
    {
        $this->holdAtMarker();

        DB::table('collision_runs')->insert([
            'step_id' => $this->step->id,
            'pid' => getmypid(),
            'recorded_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return ['pid' => getmypid(), 'step_id' => $this->step->id];
    }

    private function holdAtMarker(): void
    {
        $marker = $this->step->arguments['wait_for_marker'] ?? null;

        if (! is_string($marker)) {
            return;
        }

        // Announce the arrival, so the test knows the attempt has reached this
        // point before it takes the row away.
        touch($marker.self::ENTERED_SUFFIX);

        $deadline = microtime(true) + self::MARKER_WAIT_SECONDS;

        while (! is_file($marker)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Marker {$marker} never appeared.");
            }

            usleep(5000);
        }
    }
}
