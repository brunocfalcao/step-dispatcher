<?php

declare(strict_types=1);

/*
 * Standalone worker for the collision tests. The parent starts one of these
 * per contender with proc_open(). Each boots the package against the collision
 * database, waits for the shared start instant, runs one contended call and
 * prints a single JSON line.
 *
 * Usage: php worker.php <scenario> '<json arguments>'
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\StepDispatcherServiceProvider;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Collision\CollisionEnvironment;
use StepDispatcher\Tests\Fixtures\CollisionTestJob;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$scenario = $argv[1] ?? '';
$arguments = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

$app = TestbenchApplication::create(options: ['extra' => [
    'providers' => [StepDispatcherServiceProvider::class],
    'dont-discover' => ['*'],
]]);

CollisionEnvironment::configure($app, (string) $arguments['work_directory']);

$call = match ($scenario) {
    'start-dispatch' => static function () use ($arguments): array {
        $tickId = null;
        $acquired = StepsDispatcher::startDispatch($arguments['group'], $tickId);

        return ['acquired' => $acquired, 'tick_id' => $tickId];
    },
    'dispatch-until-drained' => static function () use ($arguments): array {
        // Every step this loop reads costs it a pause, which opens a gap between
        // reading its list of steps and claiming from that list.
        $readPauseUs = (int) ($arguments['read_pause_us'] ?? 0);

        if ($readPauseUs > 0) {
            Step::retrieved(static function () use ($readPauseUs): void {
                usleep($readPauseUs);
            });
        }

        $calls = 0;
        $deadline = microtime(true) + 25;

        while (microtime(true) < $deadline) {
            StepDispatcher::dispatch($arguments['group']);
            $calls++;

            $unfinished = Step::query()
                ->forGroup($arguments['group'])
                ->where('state', '!=', Completed::class)
                ->count();

            if ($unfinished === 0) {
                return ['calls' => $calls, 'drained' => true];
            }

            usleep(2000);
        }

        return ['calls' => $calls, 'drained' => false];
    },
    'handle' => static function () use ($arguments): array {
        $step = Step::query()->findOrFail($arguments['step_id']);

        // Built the way DispatchesJobs::dispatchSingleStep() builds a job: the
        // queue snapshot is stamped from the claimed row before handle() runs.
        $job = new CollisionTestJob;
        $job->step = $step;
        $job->stepAttemptSnapshotAvailable = true;
        $job->stepAttemptRetries = (int) $step->retries;
        $job->stepAttemptTickId = $step->tick_id === null ? null : (int) $step->tick_id;
        $job->stepAttemptStartedAt = $step->started_at?->format('Y-m-d H:i:s.u');
        $job->handle();

        return ['handled' => true];
    },
    'cancel' => static fn (): array => [
        'transitioned' => StepDispatcher::batchTransitionSteps([(int) $arguments['step_id']], Cancelled::class),
    ],
    'sweep' => static fn (): array => [
        'changed' => StepDispatcher::{$arguments['method']}($arguments['group']),
    ],
    'recover' => static fn (): array => ['exit' => Artisan::call('steps:recover-stale')],
    'group-rotation' => static function () use ($arguments): array {
        $groups = [];

        for ($i = 0; $i < (int) $arguments['calls']; $i++) {
            $groups[] = StepsDispatcher::getNextGroup();
        }

        return ['groups' => $groups];
    },
    default => throw new InvalidArgumentException("Unknown collision scenario [{$scenario}]."),
};

// Warm up before the shared instant: open the connection and autoload the job
// class tree. Otherwise that one-off cost falls inside the timed window and
// makes one contender systematically later than the other.
DB::select('SELECT 1');
class_exists(CollisionTestJob::class);

// Wait for the shared start instant. A worker that arrives late is reported,
// so the parent can see that it did not collide with the others.
$startAt = (float) $arguments['start_at_us'] + (float) ($arguments['delay_us'] ?? 0);
$lateByUs = (microtime(true) * 1_000_000) - $startAt;

while ((microtime(true) * 1_000_000) < $startAt) {
    // Spin until the shared instant.
}

$report = ['scenario' => $scenario, 'late_by_us' => max(0.0, $lateByUs)];

$startedUs = microtime(true) * 1_000_000;

try {
    $report['result'] = $call();
    $report['error'] = null;
} catch (Throwable $e) {
    $report['result'] = null;
    $report['error'] = $e::class.': '.$e->getMessage();
}

$report['start_us'] = $startedUs;
$report['end_us'] = microtime(true) * 1_000_000;

echo json_encode($report, JSON_THROW_ON_ERROR), PHP_EOL;
exit(0);
