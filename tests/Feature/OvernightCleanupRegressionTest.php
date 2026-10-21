<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;

beforeEach(function () {
    $this->flagRoot = sys_get_temp_dir().'/cleanup-regression-'.bin2hex(random_bytes(6));
    config()->set('step-dispatcher.flag_path', $this->flagRoot);
});
afterEach(function () {
    File::deleteDirectory($this->flagRoot);
});

it('reports cancellation progress only for a transition the state machine allows', function (string $relation, string $state, bool $expected) {
    $token = (string) Str::uuid();
    $parent = Step::create(['class' => 'Fixture', 'type' => 'default', 'queue' => 'default', 'group' => $token, 'index' => 1, 'block_uuid' => $token]);
    $block = $relation === 'child' ? (string) Str::uuid() : $token;
    $child = Step::create(['class' => 'Fixture', 'type' => 'default', 'queue' => 'default', 'group' => $token, 'index' => 2, 'block_uuid' => $block]);
    Step::withoutEvents(function () use ($parent, $child, $state, $relation, $block): void {
        $parent->forceFill(['state' => Failed::class, 'child_block_uuid' => $relation === 'child' ? $block : null])->save();
        $child->forceFill(['state' => $state])->save();
    });
    expect(get_class($child->fresh()->state))->toBe($state);
    $result = $relation === 'child'
        ? StepDispatcher::cascadeCancellationToChildren($token)
        : StepDispatcher::cascadeCancelledSteps($token);
    expect($result)->toBe($expected)
        ->and(get_class($child->fresh()->state))->toBe($expected ? Cancelled::class : Running::class)
        ->and(get_class($parent->fresh()->state))->toBe(Failed::class);
})->with([
    'running successor' => ['successor', Running::class, false],
    'pending successor control' => ['successor', Pending::class, true],
    'running child' => ['child', Running::class, false],
    'pending child control' => ['child', Pending::class, true],
]);
