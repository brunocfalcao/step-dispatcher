<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;

/*
|--------------------------------------------------------------------------
| Dispatcher Group Round-Robin
|--------------------------------------------------------------------------
|
| getNextGroup() stamps last_selected_at on the group it hands out so the
| next call picks the least-recently-used group. The timestamp expression
| must resolve from the model's OWN connection driver (not the default
| connection) and support every driver the package runs on — including
| SQLite, which the whole suite previously tiptoed around because the
| hardcoded NOW(6) is invalid there.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());

    // Reset to a known two-group set (the migration seeds from config,
    // which is just 'default' under the test harness).
    StepsDispatcher::query()->delete();

    foreach (['alpha', 'beta'] as $name) {
        $row = new StepsDispatcher;
        $row->group = $name;
        $row->can_dispatch = true;
        $row->save();
    }
});

it('selects a group and stamps last_selected_at on the active connection', function (): void {
    $group = StepsDispatcher::getNextGroup();

    expect($group)->toBeIn(['alpha', 'beta']);

    $row = StepsDispatcher::where('group', $group)->first();
    expect($row->last_selected_at)->not->toBeNull();
});

it('round-robins: the never-selected group is handed out before the recently-selected one', function (): void {
    // Stamp alpha as just-selected; beta has never been selected (null).
    StepsDispatcher::where('group', 'alpha')->update(['last_selected_at' => now()]);

    // NULL last_selected_at sorts first → beta must be chosen.
    expect(StepsDispatcher::getNextGroup())->toBe('beta');
});

it('does not assign group via observer round-robin without throwing on SQLite', function (): void {
    // The observer falls back to getNextGroup() when a step is created
    // without an explicit group. This previously could not run on SQLite.
    $step = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'index' => 1,
        'block_uuid' => (string) Illuminate\Support\Str::uuid(),
    ]);

    expect($step->group)->toBeIn(['alpha', 'beta']);
});
