<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\Tests\Collision\CollisionEnvironment;
use StepDispatcher\Tests\Fixtures\CollisionTestJob;

/*
|--------------------------------------------------------------------------
| The Skipped Parent Rule Under Real Contention
|--------------------------------------------------------------------------
|
| A parent's skip and the start of something inside it must not both succeed.
| Each test forces one order of events on a real server. Whichever takes the
| parent's row second has to see what the first one did.
|
*/

const SKIP_COLLISION_GROUP = 'collision';

/**
 * @param  array<string, mixed>  $arguments
 */
function skipCollisionStep(string $state, ?string $block = null, ?string $childBlock = null, array $arguments = []): Step
{
    $step = Step::query()->create([
        'class' => CollisionTestJob::class,
        'block_uuid' => $block ?? (string) Str::uuid(),
        'child_block_uuid' => $childBlock,
        'index' => 1,
        'queue' => 'default',
        'group' => SKIP_COLLISION_GROUP,
        'arguments' => $arguments,
    ]);

    DB::connection(CollisionEnvironment::CONNECTION)->table(Step::tableName())->where('id', $step->id)->update(['state' => $state]);

    return $step->fresh();
}

function skipCollisionRow(Step $step): array
{
    return (array) DB::connection(CollisionEnvironment::CONNECTION)->table(Step::tableName())->where('id', $step->id)->first();
}

function skipCollisionRuns(Step $step): int
{
    return DB::connection(CollisionEnvironment::CONNECTION)->table('collision_runs')->where('step_id', $step->id)->count();
}

it('does not start a child when its parent became Skipped while the worker waited on the parent', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = skipCollisionStep(Running::class, null, $childBlock);
    $child = skipCollisionStep(Dispatched::class, $childBlock);

    $this->runWorkerBehindUncommittedChange(
        $parent->id,
        ['state' => Skipped::class],
        ['scenario' => 'handle', 'arguments' => ['step_id' => $child->id]],
        'child start behind a parent skip',
    );

    $row = skipCollisionRow($child);

    expect(['runs' => skipCollisionRuns($child), 'state' => $row['state'], 'started_at' => $row['started_at']])
        ->toBe(['runs' => 0, 'state' => Skipped::class, 'started_at' => null]);
});

it('does not start a grandchild when its grandparent became Skipped while the worker waited on it', function (): void {
    $middleBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $top = skipCollisionStep(Running::class, null, $middleBlock);
    skipCollisionStep(Completed::class, $middleBlock, $childBlock);
    $child = skipCollisionStep(Dispatched::class, $childBlock);

    $this->runWorkerBehindUncommittedChange(
        $top->id,
        ['state' => Skipped::class],
        ['scenario' => 'handle', 'arguments' => ['step_id' => $child->id]],
        'grandchild start behind a grandparent skip',
    );

    expect(['runs' => skipCollisionRuns($child), 'state' => skipCollisionRow($child)['state']])
        ->toBe(['runs' => 0, 'state' => Skipped::class]);
});

it('refuses a parent skip when a child start committed while the skip waited on the parent', function (): void {
    $marker = $this->workDirectory.DIRECTORY_SEPARATOR.'flag'.DIRECTORY_SEPARATOR.'parent-at-skip';
    $childBlock = (string) Str::uuid();
    $parent = skipCollisionStep(Dispatched::class, null, $childBlock, ['skip_on_start' => true, 'wait_for_marker' => $marker]);

    // Pending does not count as started, so the child looks untouched to any
    // read that does not wait for the transaction below.
    $child = skipCollisionStep(Pending::class, $childBlock);

    $batch = $this->launchWorkers([['scenario' => 'handle', 'arguments' => ['step_id' => $parent->id]]]);

    try {
        $deadline = microtime(true) + 15;

        while (! is_file($marker.CollisionTestJob::ENTERED_SUFFIX) && microtime(true) < $deadline) {
            usleep(5000);
        }

        expect(is_file($marker.CollisionTestJob::ENTERED_SUFFIX))->toBeTrue();

        $this->holdingRows(
            static function ($connection) use ($parent, $child): void {
                $connection->table(Step::tableName())->where('id', $parent->id)->sharedLock()->first();
                $connection->table(Step::tableName())->where('id', $child->id)->update(['state' => Running::class]);
            },
            function () use ($marker): void {
                touch($marker);

                $this->waitForWorkerBehindRowLock('parent skip behind a child start');
            },
            function () use ($batch): void {
                $this->assertWorkersFinished($batch->wait(), 'parent skip behind a child start');
            },
        );
    } finally {
        $batch->terminate();
    }

    expect(['parent' => skipCollisionRow($parent)['state'], 'child' => skipCollisionRow($child)['state'], 'parent_runs' => skipCollisionRuns($parent)])
        ->toBe(['parent' => Running::class, 'child' => Running::class, 'parent_runs' => 0]);
});

it('leaves a step Running when the skip sweep was waiting on the row while a worker started it', function (): void {
    $childBlock = (string) Str::uuid();
    skipCollisionStep(Skipped::class, null, $childBlock);
    $child = skipCollisionStep(Dispatched::class, $childBlock);

    $payload = $this->runWorkerBehindUncommittedChange(
        $child->id,
        ['state' => Running::class, 'started_at' => now()->format('Y-m-d H:i:s.u')],
        ['scenario' => 'sweep', 'arguments' => ['method' => 'skipAllChildStepsOnParentAndChildSingleStep', 'group' => SKIP_COLLISION_GROUP]],
        'skip sweep behind a worker start',
    );

    $row = skipCollisionRow($child);

    expect(['changed' => $payload['result']['changed'], 'state' => $row['state'], 'completed_at' => $row['completed_at']])
        ->toBe(['changed' => false, 'state' => Running::class, 'completed_at' => null]);
});

it('moves a Dispatched child to Skipped when a worker refuses to start it under a Skipped parent', function (): void {
    $childBlock = (string) Str::uuid();
    skipCollisionStep(Skipped::class, null, $childBlock);
    $child = skipCollisionStep(Dispatched::class, $childBlock);

    $this->runWorkers([['scenario' => 'handle', 'arguments' => ['step_id' => $child->id]]], 'worker under a Skipped parent');

    expect(['runs' => skipCollisionRuns($child), 'state' => skipCollisionRow($child)['state']])
        ->toBe(['runs' => 0, 'state' => Skipped::class]);
});

it('still starts a step whose start lost a deadlock on its parent', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = skipCollisionStep(Running::class, null, $childBlock);
    $child = skipCollisionStep(Dispatched::class, $childBlock);

    // MySQL rolls back the transaction that changed the fewest rows, so this
    // test changes more rows than the worker, which has changed none.
    $weight = array_map(static fn (): int => skipCollisionStep(Pending::class)->id, range(1, 5));
    $batch = null;

    try {
        $this->holdingRows(
            static function ($connection) use ($parent, $weight): void {
                $connection->table(Step::tableName())->where('id', $parent->id)->lockForUpdate()->first();
                $connection->table(Step::tableName())->whereIn('id', $weight)->update(['priority' => 'high']);
            },
            function () use ($child, &$batch): void {
                $batch = $this->launchWorkers([['scenario' => 'handle', 'arguments' => ['step_id' => $child->id]]]);

                $this->waitForWorkerBehindRowLock('child start behind a locked parent');

                DB::connection(CollisionEnvironment::CONNECTION)->table(Step::tableName())->where('id', $child->id)->sharedLock()->first();
            },
            function () use (&$batch): void {
                $this->assertWorkersFinished($batch->wait(), 'child start after a deadlock');
            },
        );
    } finally {
        $batch?->terminate();
    }

    expect(['runs' => skipCollisionRuns($child), 'state' => skipCollisionRow($child)['state']])
        ->toBe(['runs' => 1, 'state' => Completed::class]);
});

it('starts many children of one parent at once, beside its sweep, without a deadlock', function (): void {
    for ($round = 1; $round <= 5; $round++) {
        $childBlock = (string) Str::uuid();
        $parent = skipCollisionStep(Running::class, null, $childBlock);
        $children = [];

        for ($i = 0; $i < 6; $i++) {
            $children[] = skipCollisionStep(Dispatched::class, $childBlock);
        }

        $workers = array_map(
            static fn (Step $child): array => ['scenario' => 'handle', 'arguments' => ['step_id' => $child->id]],
            $children,
        );

        $workers[] = ['scenario' => 'sweep', 'arguments' => ['method' => 'transitionParentsToComplete', 'group' => SKIP_COLLISION_GROUP]];
        $workers[] = ['scenario' => 'sweep', 'arguments' => ['method' => 'transitionParentsToComplete', 'group' => SKIP_COLLISION_GROUP]];

        $this->runWorkers($workers, "many children, round {$round}");

        $outcome = array_map(
            static fn (Step $child): array => [skipCollisionRow($child)['state'], skipCollisionRuns($child)],
            $children,
        );

        expect($outcome)->toBe(array_fill(0, 6, [Completed::class, 1]));
    }
});
