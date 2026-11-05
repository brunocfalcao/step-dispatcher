<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Tests\Fixtures\CollisionTestJob;

/*
|--------------------------------------------------------------------------
| Multi-process collisions
|--------------------------------------------------------------------------
|
| Each scenario starts real PHP processes that boot the package against the
| collision database and run one contended call at the same instant. Every
| assertion reads committed rows, so a fault that only shows up when two
| processes race is visible here and not in the single-process Feature suite.
|
| Set STEP_DISPATCHER_COLLISION_REPORT=1 to print how many rounds overlapped.
|
*/

const COLLISION_ROUNDS = 20;

function collisionCreateGroup(string $group): void
{
    DB::table(StepsDispatcher::tableName())->insert([
        'group' => $group,
        'can_dispatch' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function collisionDispatcherRow(string $group): object
{
    return DB::table(StepsDispatcher::tableName())->where('group', $group)->sole();
}

function collisionOpenTicks(string $group): int
{
    return DB::table(StepsDispatcherTicks::tableName())
        ->where('group', $group)
        ->whereNull('completed_at')
        ->count();
}

function collisionTickCount(string $group): int
{
    return DB::table(StepsDispatcherTicks::tableName())->where('group', $group)->count();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function collisionCreateStep(array $overrides = []): Step
{
    return Step::create(array_merge([
        'class' => CollisionTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'queue' => 'default',
        'group' => 'collision',
        'state' => Pending::class,
    ], $overrides));
}

function collisionStepRow(int $stepId): array
{
    return (array) DB::table(Step::tableName())->where('id', $stepId)->first();
}

function collisionRunsFor(int $stepId): int
{
    return DB::table('collision_runs')->where('step_id', $stepId)->count();
}

function collisionStaleRunningRow(int $stepId): void
{
    DB::table(Step::tableName())->where('id', $stepId)->update([
        'state' => Running::class,
        'started_at' => now()->subHour()->format('Y-m-d H:i:s.u'),
        'is_throttled' => false,
        'error_message' => null,
    ]);
}

it('lets exactly one of eight simultaneous startDispatch calls take the group lock in each of twenty rounds', function (): void {
    $group = 'collision-lock';
    collisionCreateGroup($group);

    $rounds = [];

    for ($round = 1; $round <= COLLISION_ROUNDS; $round++) {
        expect(['round' => $round, 'open_ticks_before' => collisionOpenTicks($group)])
            ->toBe(['round' => $round, 'open_ticks_before' => 0]);

        $ticksBefore = collisionTickCount($group);

        $payloads = $this->runWorkers(
            array_fill(0, 8, ['scenario' => 'start-dispatch', 'arguments' => ['group' => $group]]),
            "lock round {$round}",
        );

        $acquisitions = array_values(array_filter(
            $payloads,
            static fn (array $payload): bool => $payload['result']['acquired'] === true,
        ));

        expect(['round' => $round, 'acquired' => count($acquisitions)])
            ->toBe(['round' => $round, 'acquired' => 1]);

        $winnerTick = $acquisitions[0]['result']['tick_id'];

        expect(['round' => $round, 'open_ticks' => collisionOpenTicks($group), 'new_ticks' => collisionTickCount($group) - $ticksBefore])
            ->toBe(['round' => $round, 'open_ticks' => 1, 'new_ticks' => 1]);

        $rounds[] = $payloads;

        // Close the winner's tick the way its own dispatch would, so the next round starts from a free lock.
        StepsDispatcher::endDispatch(0, $group, $winnerTick);

        expect(['round' => $round, 'open_ticks_after' => collisionOpenTicks($group), 'can_dispatch' => (int) collisionDispatcherRow($group)->can_dispatch])
            ->toBe(['round' => $round, 'open_ticks_after' => 0, 'can_dispatch' => 1]);
    }

    $this->assertWindowsOverlapInMostRounds($rounds, 'startDispatch lock scenario');
});

it('lets exactly one of eight callers take over a stale dispatcher lock in each of twenty rounds', function (): void {
    $group = 'collision-stale';
    collisionCreateGroup($group);

    $rounds = [];

    for ($round = 1; $round <= COLLISION_ROUNDS; $round++) {
        // Hold the lock as a dead dispatcher would: taken, and untouched for one minute.
        DB::table(StepsDispatcher::tableName())->where('group', $group)->update([
            'can_dispatch' => false,
            'updated_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]);

        $heldRow = collisionDispatcherRow($group);
        $heldUpdatedAt = Carbon::parse($heldRow->updated_at);

        expect(['round' => $round, 'can_dispatch' => (int) $heldRow->can_dispatch, 'older_than_20s' => $heldUpdatedAt->lt(now()->subSeconds(20))])
            ->toBe(['round' => $round, 'can_dispatch' => 0, 'older_than_20s' => true]);

        $payloads = $this->runWorkers(
            array_fill(0, 8, ['scenario' => 'start-dispatch', 'arguments' => ['group' => $group]]),
            "stale lock round {$round}",
        );

        $acquisitions = array_values(array_filter(
            $payloads,
            static fn (array $payload): bool => $payload['result']['acquired'] === true,
        ));

        expect(['round' => $round, 'acquired' => count($acquisitions)])
            ->toBe(['round' => $round, 'acquired' => 1]);

        $rounds[] = $payloads;

        StepsDispatcher::endDispatch(0, $group, $acquisitions[0]['result']['tick_id']);
    }

    $this->assertWindowsOverlapInMostRounds($rounds, 'stale lock scenario');
});

it('completes every step exactly once when a fast dispatch loop races three slow ones, in each of ten rounds', function (): void {
    $group = 'collision-dispatch';
    collisionCreateGroup($group);

    $rounds = [];

    for ($round = 1; $round <= 10; $round++) {
        $stepIds = [];

        for ($i = 0; $i < 10; $i++) {
            $stepIds[] = collisionCreateStep(['group' => $group])->id;
        }

        // A slow loop pauses after reading its list of steps and before claiming
        // them, so by the time it claims, the fast loop has already finished some
        // of those steps. Those steps must not run a second time.
        $payloads = $this->runWorkers(array_map(
            static fn (int $readPauseUs): array => [
                'scenario' => 'dispatch-until-drained',
                'arguments' => ['group' => $group, 'read_pause_us' => $readPauseUs],
            ],
            [0, 3000, 3000, 3000],
        ), "competing dispatch loops round {$round}");

        expect(['round' => $round, 'drained' => array_column(array_column($payloads, 'result'), 'drained')])
            ->toBe(['round' => $round, 'drained' => [true, true, true, true]]);

        $states = DB::table(Step::tableName())->whereIn('id', $stepIds)->pluck('state', 'id')->all();
        expect(['round' => $round, 'states' => array_count_values($states)])
            ->toBe(['round' => $round, 'states' => [Completed::class => 10]]);

        $runCounts = array_map(collisionRunsFor(...), $stepIds);
        expect(['round' => $round, 'runs' => array_count_values($runCounts)])
            ->toBe(['round' => $round, 'runs' => [1 => 10]]);

        $rounds[] = $payloads;
    }

    $this->assertWindowsOverlapInMostRounds($rounds, 'competing dispatch loops');
});

it('runs a dispatched step exactly once when six deliveries of its job race in each of twenty rounds', function (): void {
    $rounds = [];
    $stepIds = [];

    for ($round = 1; $round <= COLLISION_ROUNDS; $round++) {
        $step = collisionCreateStep(['state' => Dispatched::class]);
        $stepIds[] = $step->id;

        $payloads = $this->runWorkers(
            array_fill(0, 6, ['scenario' => 'handle', 'arguments' => ['step_id' => $step->id]]),
            "duplicate delivery round {$round}",
        );

        expect(['round' => $round, 'runs' => collisionRunsFor($step->id)])
            ->toBe(['round' => $round, 'runs' => 1]);

        expect(['round' => $round, 'state' => collisionStepRow($step->id)['state']])
            ->toBe(['round' => $round, 'state' => Completed::class]);

        $rounds[] = $payloads;
    }

    expect(DB::table('collision_runs')->whereIn('step_id', $stepIds)->count())->toBe(COLLISION_ROUNDS);

    $this->assertWindowsOverlapInMostRounds($rounds, 'duplicate delivery scenario');
});

it('never runs a step whose cancellation landed first, and never cancels one whose work already ran', function (): void {
    $rounds = [];
    $outcomes = ['cancelled' => 0, 'completed' => 0];

    for ($round = 1; $round <= 30; $round++) {
        $step = collisionCreateStep(['state' => Dispatched::class]);

        // Either process may start a few milliseconds before the other, so the
        // rounds cover both orders: cancel before the claim, and cancel after it.
        $payloads = $this->runWorkers([
            ['scenario' => 'handle', 'arguments' => ['step_id' => $step->id, 'delay_us' => random_int(0, 4000)]],
            ['scenario' => 'cancel', 'arguments' => ['step_id' => $step->id, 'delay_us' => random_int(0, 4000)]],
        ], "start versus cancel round {$round}");

        $runs = collisionRunsFor($step->id);
        $final = collisionStepRow($step->id)['state'];
        $cancelled = $final === Cancelled::class;

        $outcomes[$cancelled ? 'cancelled' : 'completed']++;

        expect(['round' => $round, 'final' => $final, 'runs' => $runs])
            ->toBe(['round' => $round, 'final' => $final, 'runs' => $cancelled ? 0 : 1]);

        $rounds[] = $payloads;
    }

    $this->assertWindowsOverlapInMostRounds($rounds, 'start versus cancel scenario');

    // Both branches must have happened, or the rounds never exercised a race between claim and cancel.
    expect($outcomes['completed'])->toBeGreaterThan(0, 'no round let the job claim the step first');
    expect($outcomes['cancelled'])->toBeGreaterThan(0, 'no round let the cancel land first');

    // Printed with the report flag so a run shows which branch the races took.
    if (getenv('STEP_DISPATCHER_COLLISION_REPORT') === '1') {
        fwrite(STDERR, sprintf("\n[collision] start versus cancel outcomes: %s\n", json_encode($outcomes)));
    }
});

it('leaves a step running when a cancel was waiting on the row while a worker started it', function (): void {
    $step = collisionCreateStep(['state' => Dispatched::class]);
    $startedAt = now()->format('Y-m-d H:i:s.u');

    $payload = $this->runWorkerBehindUncommittedChange(
        $step->id,
        ['state' => Running::class, 'started_at' => $startedAt],
        ['scenario' => 'cancel', 'arguments' => ['step_id' => $step->id]],
        'cancel behind a worker start',
    );

    $row = collisionStepRow($step->id);

    expect(['moved' => $payload['result']['transitioned'], 'state' => $row['state'], 'completed_at' => $row['completed_at']])
        ->toBe(['moved' => 0, 'state' => Running::class, 'completed_at' => null]);
});

it('keeps a finished step finished when a cancel was waiting on the row while its worker finished', function (): void {
    $step = collisionCreateStep(['state' => Dispatched::class]);

    $payload = $this->runWorkerBehindUncommittedChange(
        $step->id,
        ['state' => Completed::class, 'completed_at' => now()->format('Y-m-d H:i:s.u')],
        ['scenario' => 'cancel', 'arguments' => ['step_id' => $step->id]],
        'cancel behind a worker finish',
    );

    expect(['moved' => $payload['result']['transitioned'], 'state' => collisionStepRow($step->id)['state']])
        ->toBe(['moved' => 0, 'state' => Completed::class]);
});

it('does not start the work of a step whose cancel committed while the worker waited on the row', function (): void {
    $step = collisionCreateStep(['state' => Dispatched::class]);

    $this->runWorkerBehindUncommittedChange(
        $step->id,
        ['state' => Cancelled::class, 'completed_at' => now()->format('Y-m-d H:i:s.u')],
        ['scenario' => 'handle', 'arguments' => ['step_id' => $step->id]],
        'worker behind a cancel',
    );

    expect(['runs' => collisionRunsFor($step->id), 'state' => collisionStepRow($step->id)['state']])
        ->toBe(['runs' => 0, 'state' => Cancelled::class]);
});

it('cancels a step that no worker has touched', function (): void {
    $step = collisionCreateStep(['state' => Dispatched::class]);

    $payloads = $this->runWorkers([
        ['scenario' => 'cancel', 'arguments' => ['step_id' => $step->id]],
    ], 'cancel with no competition');

    expect(['moved' => $payloads[0]['result']['transitioned'], 'state' => collisionStepRow($step->id)['state']])
        ->toBe(['moved' => 1, 'state' => Cancelled::class]);
});

it('keeps the children of a parent whose worker started it while the cascade was waiting, and still cancels what follows', function (): void {
    $block = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    collisionCreateStep(['block_uuid' => $block, 'index' => 1, 'state' => Failed::class]);
    $parent = collisionCreateStep([
        'block_uuid' => $block,
        'index' => 2,
        'state' => Dispatched::class,
        'child_block_uuid' => $childBlock,
    ]);
    $follower = collisionCreateStep(['block_uuid' => $block, 'index' => 3, 'state' => Pending::class]);
    $child = collisionCreateStep(['block_uuid' => $childBlock, 'index' => 1, 'state' => Pending::class]);

    $this->runWorkerBehindUncommittedChange(
        $parent->id,
        ['state' => Running::class, 'started_at' => now()->format('Y-m-d H:i:s.u')],
        ['scenario' => 'sweep', 'arguments' => ['method' => 'cascadeCancelledSteps', 'group' => 'collision']],
        'cascade behind a parent start',
    );

    expect([
        'parent' => collisionStepRow($parent->id)['state'],
        'child' => collisionStepRow($child->id)['state'],
        'follower' => collisionStepRow($follower->id)['state'],
    ])->toBe([
        'parent' => Running::class,
        'child' => Pending::class,
        'follower' => Cancelled::class,
    ]);
});

it('keeps a finished step finished when a skip sweep was waiting on the row while its worker finished', function (): void {
    $childBlock = (string) Str::uuid();

    collisionCreateStep(['state' => Skipped::class, 'child_block_uuid' => $childBlock]);
    $child = collisionCreateStep(['block_uuid' => $childBlock, 'state' => Dispatched::class]);

    $payload = $this->runWorkerBehindUncommittedChange(
        $child->id,
        ['state' => Completed::class, 'completed_at' => now()->format('Y-m-d H:i:s.u')],
        ['scenario' => 'sweep', 'arguments' => ['method' => 'skipAllChildStepsOnParentAndChildSingleStep', 'group' => 'collision']],
        'skip sweep behind a worker finish',
    );

    expect(['changed' => $payload['result']['changed'], 'state' => collisionStepRow($child->id)['state']])
        ->toBe(['changed' => false, 'state' => Completed::class]);
});

/**
 * @param  array<string, mixed>  $parentOverrides
 * @return array{parent: Step, child: Step}
 */
function collisionParentWithChild(string $childState, array $parentOverrides = []): array
{
    $childBlock = (string) Str::uuid();

    return [
        'parent' => collisionCreateStep(array_merge([
            'state' => Running::class,
            'child_block_uuid' => $childBlock,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
        ], $parentOverrides)),
        'child' => collisionCreateStep(['block_uuid' => $childBlock, 'state' => $childState]),
    ];
}

it('concludes a parent no worker competes for', function (string $method, string $childState, string $expected): void {
    ['parent' => $parent] = collisionParentWithChild($childState);

    $payloads = $this->runWorkers([
        ['scenario' => 'sweep', 'arguments' => ['method' => $method, 'group' => 'collision']],
    ], "{$method} with no competition");

    expect(['changed' => $payloads[0]['result']['changed'], 'state' => collisionStepRow($parent->id)['state']])
        ->toBe(['changed' => true, 'state' => $expected]);
})->with([
    'failure sweep' => ['transitionParentsToFailed', Failed::class, Failed::class],
    'stop sweep' => ['transitionParentsToStopped', Stopped::class, Stopped::class],
    'completion sweep' => ['transitionParentsToComplete', Completed::class, Completed::class],
]);

it('keeps the result a worker recorded on a parent while a sweep was waiting on the row', function (string $method, string $childState, string $workerResult, ?string $workerError): void {
    ['parent' => $parent] = collisionParentWithChild($childState);
    $completedAt = now()->format('Y-m-d H:i:s.u');

    $payload = $this->runWorkerBehindUncommittedChange(
        $parent->id,
        ['state' => $workerResult, 'completed_at' => $completedAt, 'error_message' => $workerError],
        ['scenario' => 'sweep', 'arguments' => ['method' => $method, 'group' => 'collision']],
        "{$method} behind a worker result",
    );

    $row = collisionStepRow($parent->id);

    expect(['changed' => $payload['result']['changed'], 'state' => $row['state'], 'error' => $row['error_message']])
        ->toBe(['changed' => false, 'state' => $workerResult, 'error' => $workerError]);
})->with([
    'completion sweep behind a worker failure' => ['transitionParentsToComplete', Completed::class, Failed::class, 'the parent job failed'],
    'failure sweep behind a worker completion' => ['transitionParentsToFailed', Failed::class, Completed::class, null],
    'stop sweep behind a worker failure' => ['transitionParentsToStopped', Stopped::class, Failed::class, 'the parent job failed'],
]);

it('keeps a parent waiting for its retry when the failure sweep was waiting on the row', function (): void {
    ['parent' => $parent] = collisionParentWithChild(Failed::class);

    $payload = $this->runWorkerBehindUncommittedChange(
        $parent->id,
        ['state' => Pending::class, 'retries' => 1],
        ['scenario' => 'sweep', 'arguments' => ['method' => 'transitionParentsToFailed', 'group' => 'collision']],
        'failure sweep behind a worker retry',
    );

    $row = collisionStepRow($parent->id);

    expect(['changed' => $payload['result']['changed'], 'state' => $row['state'], 'retries' => (int) $row['retries'], 'error' => $row['error_message']])
        ->toBe(['changed' => false, 'state' => Pending::class, 'retries' => 1, 'error' => null]);
});

it('moves on to the next parent when the first was concluded while the sweep waited on its row', function (): void {
    ['parent' => $concluded] = collisionParentWithChild(Failed::class);
    ['parent' => $next] = collisionParentWithChild(Failed::class);

    $payload = $this->runWorkerBehindUncommittedChange(
        $concluded->id,
        ['state' => Completed::class, 'completed_at' => now()->format('Y-m-d H:i:s.u')],
        ['scenario' => 'sweep', 'arguments' => ['method' => 'transitionParentsToFailed', 'group' => 'collision']],
        'failure sweep moving past a concluded parent',
    );

    expect([
        'changed' => $payload['result']['changed'],
        'concluded' => collisionStepRow($concluded->id)['state'],
        'next' => collisionStepRow($next->id)['state'],
    ])->toBe([
        'changed' => true,
        'concluded' => Completed::class,
        'next' => Failed::class,
    ]);
});

it('keeps a recovered step exactly as recovery left it when the worker that lost it finishes later', function (): void {
    $marker = $this->workDirectory.DIRECTORY_SEPARATOR.'release-obsolete-worker';

    $step = collisionCreateStep([
        'state' => Dispatched::class,
        'arguments' => ['wait_for_marker' => $marker],
    ]);

    $batch = $this->launchWorkers([
        ['scenario' => 'handle', 'arguments' => ['step_id' => $step->id]],
    ]);

    try {
        // Worker A must be inside compute() before the row is taken away, or the
        // attempt is superseded before any work runs and the test proves nothing.
        $deadline = microtime(true) + 20;

        while (! is_file($marker.CollisionTestJob::ENTERED_SUFFIX)) {
            if (microtime(true) > $deadline) {
                $this->fail('The obsolete worker never entered compute() within 20 seconds.');
            }

            usleep(5000);
        }

        expect(collisionStepRow($step->id)['state'])->toBe(Running::class);

        // Make the claim stale, then let the real recovery command act on it.
        DB::table(Step::tableName())->where('id', $step->id)->update([
            'started_at' => now()->subHour()->format('Y-m-d H:i:s.u'),
        ]);

        expect(Artisan::call('steps:recover-stale'))->toBe(0);

        $afterRecovery = collisionStepRow($step->id);

        expect(['state' => $afterRecovery['state'], 'retries' => (int) $afterRecovery['retries']])
            ->toBe(['state' => Pending::class, 'retries' => 1]);

        // Release worker A. It must now find that it no longer owns the row.
        touch($marker);

        $payloads = $this->assertWorkersFinished($batch->wait(), 'obsolete worker');
    } finally {
        $batch->terminate();
    }

    expect(collisionStepRow($step->id))->toBe($afterRecovery)
        ->and(collisionRunsFor($step->id))->toBe(1)
        ->and($payloads[0]['result']['handled'])->toBeTrue();
});

it('recovers a stale step exactly once when four recoverers race on it, in each of twenty rounds', function (): void {
    $step = collisionCreateStep();

    $rounds = [];

    for ($round = 1; $round <= COLLISION_ROUNDS; $round++) {
        collisionStaleRunningRow($step->id);

        $retriesBefore = (int) collisionStepRow($step->id)['retries'];

        $payloads = $this->runWorkers(
            array_fill(0, 4, ['scenario' => 'recover', 'arguments' => []]),
            "competing recovery round {$round}",
        );

        $row = collisionStepRow($step->id);

        expect([
            'round' => $round,
            'state' => $row['state'],
            'retries' => (int) $row['retries'],
            'increment' => (int) $row['retries'] - $retriesBefore,
        ])->toBe([
            'round' => $round,
            'state' => Pending::class,
            'retries' => $round,
            'increment' => 1,
        ]);

        $rounds[] = $payloads;
    }

    $this->assertWindowsOverlapInMostRounds($rounds, 'competing recovery scenario');
});

it('returns only configured group names, 200 in total, when eight callers rotate groups together', function (): void {
    $groups = ['alpha', 'beta', 'gamma', 'delta'];

    // The migration seeds groups from config, so start from an empty table.
    DB::table(StepsDispatcher::tableName())->delete();

    foreach ($groups as $group) {
        collisionCreateGroup($group);
    }

    $payloads = $this->runWorkers(
        array_fill(0, 8, ['scenario' => 'group-rotation', 'arguments' => ['calls' => 25]]),
        'group rotation',
    );

    $returned = [];

    foreach ($payloads as $payload) {
        expect(count($payload['result']['groups']))->toBe(25);

        array_push($returned, ...$payload['result']['groups']);
    }

    expect(count($returned))->toBe(200)
        ->and(array_values(array_diff($returned, $groups)))->toBe([]);

    $distinct = array_values(array_unique($returned));
    sort($distinct);

    $expected = $groups;
    sort($expected);

    expect($distinct)->toBe($expected);
});
