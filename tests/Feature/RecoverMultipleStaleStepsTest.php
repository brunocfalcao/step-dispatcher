<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

it('recovers every stale step and reports the oldest original start without touching a live worker', function (bool $oldestFirst): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    $this->travelTo(now()->startOfSecond());
    Event::fake([StaleStepsDetected::class]);
    $token = 'multi-stale-'.Str::uuid();
    $steps = [];
    foreach ([$oldestFirst ? 20 : 10, $oldestFirst ? 10 : 20, 15, 1] as $index => $minutes) {
        $steps[] = Step::withoutEvents(fn () => Step::create([
            'class' => PrefixCarryingTestJob::class, 'block_uuid' => $token.'-'.$index,
            'index' => 1, 'queue' => 'default', 'group' => $token,
            'state' => Running::class, 'started_at' => now()->subMinutes($minutes),
            'retries' => $index === 2 ? 1 : 0,
        ]));
    }
    foreach ($steps as $step) {
        expect($step->state)->toBeInstanceOf(Running::class);
    }
    $liveBefore = $steps[3]->fresh()->getAttributes();
    $this->artisan('steps:recover-stale')->assertSuccessful();
    foreach (array_slice($steps, 0, 2) as $step) {
        expect($step->fresh()->state)->toBeInstanceOf(Pending::class)
            ->and($step->fresh()->retries)->toBe(1)
            ->and($step->fresh()->started_at)->toBeNull();
    }
    expect($steps[2]->fresh()->state)->toBeInstanceOf(Failed::class)
        ->and($steps[2]->fresh()->retries)->toBe(1)
        ->and($steps[3]->fresh()->getAttributes())->toBe($liveBefore);
    $oldestId = $steps[$oldestFirst ? 0 : 1]->id;
    Event::assertDispatched(StaleStepsDetected::class, fn (StaleStepsDetected $event): bool => $event->reason === 'stale_running_steps_recovered' && $event->count === 3
        && $event->oldestStep->id === $oldestId
        && $event->oldestStep->started_at->equalTo(now()->subMinutes(20)));
})->with([true, false]);
