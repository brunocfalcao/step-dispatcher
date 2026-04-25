<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function seedWatchdogStep(array $attrs): Step
{
    return Step::create(array_merge([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'watchdog-test',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ], $attrs));
}

/**
 * Group-progress watchdog. The 2026-04-25 wedge proved the existing
 * stuck-step detector is necessary but not sufficient: phase 0 of the
 * dispatcher tick was returning `true` early on Skipped parents with
 * empty child blocks, so dispatch never ran — but no individual step
 * was Running long enough to look stale, no Dispatched step was
 * stuck, no lock was held. The detector found nothing while four
 * groups silently bled for 16h.
 *
 * The generalised watchdog: per group, if there are Pending steps but
 * no terminal-state step has been updated within the progress
 * threshold, the group has stalled. Fires a StaleStepsDetected event
 * with `reason='group_no_progress'` so consuming apps can route it to
 * Pushover / Slack alongside the existing stuck-step canonicals.
 *
 * The threshold-default trade-off: too low (sub-minute) chatters on
 * legitimate idle gaps; too high (sub-hour) loses the wedge for
 * meaningful time. 10 minutes is the production sweet spot.
 */
it('fires StaleStepsDetected with reason=group_no_progress when a group has Pending steps but stale terminal updates', function () {
    Event::fake([StaleStepsDetected::class]);

    // Group "wedged" — has Pending work but the only terminal-state step
    // updated 20 minutes ago. Threshold is 600s (10 min) → wedge.
    seedWatchdogStep([
        'group' => 'wedged-group',
        'state' => Pending::class,
    ]);

    $stale = seedWatchdogStep([
        'group' => 'wedged-group',
    ]);
    Step::withoutEvents(function () use ($stale) {
        Step::where('id', $stale->id)->update([
            'state' => Completed::class,
            'updated_at' => now()->subMinutes(20),
        ]);
    });

    // Group "healthy" — has Pending work AND a recent terminal update.
    // Must NOT fire the watchdog event for this group.
    seedWatchdogStep([
        'group' => 'healthy-group',
        'state' => Pending::class,
    ]);
    $recent = seedWatchdogStep([
        'group' => 'healthy-group',
    ]);
    Step::withoutEvents(function () use ($recent) {
        Step::where('id', $recent->id)->update([
            'state' => Completed::class,
            'updated_at' => now()->subSeconds(30),
        ]);
    });

    $this->artisan('steps:recover-stale', [
        '--watchdog-progress' => true,
        '--progress-threshold' => 600,
    ])->assertSuccessful();

    Event::assertDispatched(StaleStepsDetected::class, function (StaleStepsDetected $event) {
        return $event->reason === 'group_no_progress'
            && ($event->context['group'] ?? null) === 'wedged-group'
            && ($event->context['pending_count'] ?? 0) >= 1;
    });

    Event::assertNotDispatched(StaleStepsDetected::class, function (StaleStepsDetected $event) {
        return $event->reason === 'group_no_progress'
            && ($event->context['group'] ?? null) === 'healthy-group';
    });
});

it('does not fire group_no_progress when a group has zero Pending steps even if terminal updates are stale', function () {
    Event::fake([StaleStepsDetected::class]);

    // Idle group: only stale terminals, no Pending work. The watchdog must
    // stay quiet — the alert is "the group can't drain its work", not "the
    // group has done no work recently". Otherwise every quiet group at
    // 03:00 UTC pages the operator.
    $stale = seedWatchdogStep(['group' => 'idle-group']);
    Step::withoutEvents(function () use ($stale) {
        Step::where('id', $stale->id)->update([
            'state' => Completed::class,
            'updated_at' => now()->subHour(),
        ]);
    });

    $this->artisan('steps:recover-stale', [
        '--watchdog-progress' => true,
        '--progress-threshold' => 600,
    ])->assertSuccessful();

    Event::assertNotDispatched(StaleStepsDetected::class, function (StaleStepsDetected $event) {
        return $event->reason === 'group_no_progress';
    });
});
