<?php

declare(strict_types=1);

use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\Tests\Fixtures\ConstructorArgTestJob;

function queuedRecoveryStep(int $retries = 0): Step
{
    $step = Step::create([
        'class' => ConstructorArgTestJob::class, 'arguments' => ['value' => 42],
        'queue' => 'default', 'group' => 'queued-recovery-'.Str::uuid(),
        'index' => 1, 'block_uuid' => (string) Str::uuid(),
    ]);
    DB::table('steps')->where('id', $step->id)->update([
        'state' => Dispatched::class, 'retries' => $retries,
        'updated_at' => now()->subHours(2),
    ]);

    return $step->refresh();
}

it('preserves a dispatched step whose payload remains queued while recovering a missing payload', function (string $location) {
    $this->freezeTime();
    config(['step-dispatcher.flag_path' => sys_get_temp_dir()]);
    $queued = queuedRecoveryStep(1);
    $missing = queuedRecoveryStep();
    $job = new ConstructorArgTestJob(42);
    $job->step = $queued;
    $payload = InspectedJob::fromPayload(json_encode([
        'displayName' => ConstructorArgTestJob::class,
        'data' => ['commandName' => ConstructorArgTestJob::class, 'command' => serialize($job)],
    ], JSON_THROW_ON_ERROR));
    $connection = Mockery::mock(RedisQueue::class);
    foreach (['pendingJobs', 'delayedJobs', 'reservedJobs'] as $method) {
        $connection->shouldReceive($method)->with('default')
            ->andReturn(collect($method === $location ? [$payload] : []));
    }
    Queue::shouldReceive('connection')->andReturn($connection);
    expect($queued->state)->toBeInstanceOf(Dispatched::class);
    expect($missing->state)->toBeInstanceOf(Dispatched::class);

    Artisan::call('steps:recover-stale', ['--recover-dispatched' => true]);

    expect($queued->refresh()->state)->toBeInstanceOf(Dispatched::class);
    expect($queued->retries)->toBe(1);
    expect($queued->error_message)->toBeNull();
    expect($missing->refresh()->state)->toBeInstanceOf(Pending::class);
    expect($missing->retries)->toBe(1);
})->with(['pendingJobs', 'delayedJobs', 'reservedJobs']);

it('does not spend retries when Redis payload inspection is unavailable', function () {
    config(['step-dispatcher.flag_path' => sys_get_temp_dir()]);
    $step = queuedRecoveryStep();
    $connection = Mockery::mock(RedisQueue::class);
    $connection->shouldReceive('pendingJobs')->andThrow(new RuntimeException('Redis unavailable'));
    Queue::shouldReceive('connection')->andReturn($connection);
    expect($step->retries)->toBe(0);

    Artisan::call('steps:recover-stale', ['--recover-dispatched' => true]);

    expect($step->refresh()->state)->toBeInstanceOf(Dispatched::class);
    expect($step->retries)->toBe(0);
});

it('does not confuse another table prefix with the same step id', function () {
    config(['step-dispatcher.flag_path' => sys_get_temp_dir()]);
    $step = queuedRecoveryStep();
    $job = new ConstructorArgTestJob(42);
    $job->step = $step;
    $job->stepPrefix = 'other_';
    $payload = InspectedJob::fromPayload(json_encode([
        'data' => ['commandName' => ConstructorArgTestJob::class, 'command' => serialize($job)],
    ], JSON_THROW_ON_ERROR));
    $connection = Mockery::mock(RedisQueue::class);
    $connection->shouldReceive('pendingJobs')->andReturn(collect([$payload]));
    $connection->shouldReceive('delayedJobs', 'reservedJobs')->andReturn(collect());
    Queue::shouldReceive('connection')->andReturn($connection);
    expect($step->retries)->toBe(0);

    Artisan::call('steps:recover-stale', ['--recover-dispatched' => true]);

    expect($step->refresh()->state)->toBeInstanceOf(Pending::class);
    expect($step->retries)->toBe(1);
});

it('leaves opaque step payloads untouched instead of guessing they were lost', function () {
    config(['step-dispatcher.flag_path' => sys_get_temp_dir()]);
    $step = queuedRecoveryStep();
    $payload = InspectedJob::fromPayload(json_encode([
        'data' => ['commandName' => ConstructorArgTestJob::class, 'command' => 'encrypted-payload'],
    ], JSON_THROW_ON_ERROR));
    $connection = Mockery::mock(RedisQueue::class);
    $connection->shouldReceive('pendingJobs')->andReturn(collect([$payload]));
    Queue::shouldReceive('connection')->andReturn($connection);
    expect($step->retries)->toBe(0);

    Artisan::call('steps:recover-stale', ['--recover-dispatched' => true]);

    expect($step->refresh()->state)->toBeInstanceOf(Dispatched::class);
    expect($step->retries)->toBe(0);
});

it('protects queued steps whose job class belongs to another consumer application', function () {
    config(['step-dispatcher.flag_path' => sys_get_temp_dir()]);
    $step = queuedRecoveryStep(1);
    $remoteClass = 'OtherApplication\\Jobs\\ReviewEvidence';
    DB::table('steps')->where('id', $step->id)->update(['class' => $remoteClass]);
    $job = new ConstructorArgTestJob(42);
    $job->step = $step;
    $command = preg_replace('/^O:\\d+:"[^"]+":/', 'O:'.strlen($remoteClass).':"'.$remoteClass.'":', serialize($job));
    $payload = InspectedJob::fromPayload(json_encode([
        'data' => ['commandName' => $remoteClass, 'command' => $command],
    ], JSON_THROW_ON_ERROR));
    $connection = Mockery::mock(RedisQueue::class);
    $connection->shouldReceive('pendingJobs')->andReturn(collect([$payload]));
    $connection->shouldReceive('delayedJobs', 'reservedJobs')->andReturn(collect());
    Queue::shouldReceive('connection')->andReturn($connection);
    expect(class_exists($remoteClass))->toBeFalse();

    Artisan::call('steps:recover-stale', ['--recover-dispatched' => true]);

    expect($step->refresh()->state)->toBeInstanceOf(Dispatched::class);
    expect($step->retries)->toBe(1);
});
