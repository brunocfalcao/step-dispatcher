<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;

it('does not let an expired tick release or complete its successor', function () {
    $group = 'owner-'.bin2hex(random_bytes(6));
    expect(StepsDispatcher::startDispatch($group))->toBeTrue();
    $dispatcher = StepsDispatcher::where('group', $group)->firstOrFail();
    $old = (int) $dispatcher->current_tick_id;
    $this->travel(21)->seconds();
    expect(StepsDispatcher::startDispatch($group))->toBeTrue();
    $new = (int) $dispatcher->fresh()->current_tick_id;
    expect($new)->not->toBe($old)
        ->and($dispatcher->fresh()->can_dispatch)->toBeFalse();

    StepsDispatcher::endDispatch(8, $group, $old);

    expect((int) $dispatcher->fresh()->current_tick_id)->toBe($new)
        ->and($dispatcher->fresh()->can_dispatch)->toBeFalse()
        ->and(StepsDispatcherTicks::findOrFail($new)->completed_at)->toBeNull()
        ->and(Cache::get('current_tick_id:'.$group))->toBe($new);

    StepsDispatcher::endDispatch(8, $group, $new);

    expect($dispatcher->fresh()->current_tick_id)->toBeNull()
        ->and($dispatcher->fresh()->can_dispatch)->toBeTrue()
        ->and(StepsDispatcherTicks::findOrFail($new)->progress)->toBe(8)
        ->and(Cache::get('current_tick_id:'.$group))->toBeNull();
});

it('keeps the legacy paired acquisition and completion interface', function () {
    $group = 'legacy-'.bin2hex(random_bytes(6));
    expect(StepsDispatcher::startDispatch($group))->toBeTrue();
    StepsDispatcher::endDispatch(8, $group);
    expect(StepsDispatcher::where('group', $group)->firstOrFail()->can_dispatch)->toBeTrue();
});
