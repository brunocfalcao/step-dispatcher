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

    // Group "wedged" — Pending work that has ITSELF been waiting longer than
    // the threshold (created 20 min ago) AND the only terminal-state step
    // updated 20 minutes ago. That is a genuine stall → fire. Aging the
    // Pending step is what separates a real wedge from a freshly-arrived step
    // in a sparse group (see the sparse-group false-positive test below).
    $wedged = seedWatchdogStep([
        'group' => 'wedged-group',
        'state' => Pending::class,
    ]);
    Step::withoutEvents(function () use ($wedged) {
        Step::where('id', $wedged->id)->update(['created_at' => now()->subMinutes(20)]);
    });

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

it('does not fire group_no_progress when the only Pending steps in a group are throttle-waiting', function () {
    Event::fake([StaleStepsDetected::class]);

    // Throttle-waiting group: a rate-limited step (TAAPI/exchange 429)
    // reschedules itself back into Pending with is_throttled=true. It is
    // progressing — just unable to cross the finish line until the API
    // window reopens — so the watchdog must stay quiet even though no
    // terminal step has landed for 20 minutes. This is the chronic
    // backpressure false-positive fix A targets.
    $throttled = seedWatchdogStep([
        'group' => 'throttled-group',
        'state' => Pending::class,
    ]);
    Step::withoutEvents(function () use ($throttled) {
        Step::where('id', $throttled->id)->update(['is_throttled' => true]);
    });

    $stale = seedWatchdogStep(['group' => 'throttled-group']);
    Step::withoutEvents(function () use ($stale) {
        Step::where('id', $stale->id)->update([
            'state' => Completed::class,
            'updated_at' => now()->subMinutes(20),
        ]);
    });

    $this->artisan('steps:recover-stale', [
        '--watchdog-progress' => true,
        '--progress-threshold' => 600,
    ])->assertSuccessful();

    Event::assertNotDispatched(StaleStepsDetected::class, function (StaleStepsDetected $event) {
        return $event->reason === 'group_no_progress'
            && ($event->context['group'] ?? null) === 'throttled-group';
    });
});

it('still fires group_no_progress for genuinely-waiting Pending steps and counts only non-throttled work', function () {
    Event::fake([StaleStepsDetected::class]);

    // Mixed group: one throttle-waiting step (excluded from the tally) plus
    // one genuinely dispatchable Pending step the group cannot drain. The
    // wedge is real for the non-throttled step → fire — but pending_count
    // must reflect only the non-throttled tally (1, not 2), so the operator
    // sees the true stuck-work count, not inflated by rate-limited waiters.
    $throttled = seedWatchdogStep([
        'group' => 'mixed-group',
        'state' => Pending::class,
    ]);
    Step::withoutEvents(function () use ($throttled) {
        Step::where('id', $throttled->id)->update(['is_throttled' => true]);
    });

    $genuine = seedWatchdogStep([
        'group' => 'mixed-group',
        'state' => Pending::class,
    ]);
    Step::withoutEvents(function () use ($genuine) {
        Step::where('id', $genuine->id)->update(['created_at' => now()->subMinutes(20)]);
    });

    $stale = seedWatchdogStep(['group' => 'mixed-group']);
    Step::withoutEvents(function () use ($stale) {
        Step::where('id', $stale->id)->update([
            'state' => Completed::class,
            'updated_at' => now()->subMinutes(20),
        ]);
    });

    $this->artisan('steps:recover-stale', [
        '--watchdog-progress' => true,
        '--progress-threshold' => 600,
    ])->assertSuccessful();

    Event::assertDispatched(StaleStepsDetected::class, function (StaleStepsDetected $event) {
        return $event->reason === 'group_no_progress'
            && ($event->context['group'] ?? null) === 'mixed-group'
            && ($event->context['pending_count'] ?? 0) === 1;
    });
});

it('does not fire group_no_progress for a freshly-created Pending step even when the group last terminal update is stale', function () {
    Event::fake([StaleStepsDetected::class]);

    // Sparse / event-driven groups (the trading_* set, fed by irregular
    // Binance user-data events) sit idle for hours, so the group's last
    // terminal step is always older than the threshold the moment a new step
    // arrives. A brand-new Pending step is NOT a wedge — it has simply not
    // had time to drain. This reproduces the 2026-06-09 gamma false positive:
    // a ProcessUserDataEventJob created at 18:00:01 and completed at 18:00:02,
    // caught mid-flight by the every-minute watchdog tick, paged CRITICAL on a
    // group whose previous event had landed 72 minutes earlier. The pending
    // work must itself have been waiting past the threshold before we alarm.
    seedWatchdogStep([
        'group' => 'sparse-group',
        'state' => Pending::class,
    ]); // created_at = now() — brand new, well inside the threshold

    $stale = seedWatchdogStep(['group' => 'sparse-group']);
    Step::withoutEvents(function () use ($stale) {
        Step::where('id', $stale->id)->update([
            'state' => Completed::class,
            'updated_at' => now()->subMinutes(72),
        ]);
    });

    $this->artisan('steps:recover-stale', [
        '--watchdog-progress' => true,
        '--progress-threshold' => 600,
    ])->assertSuccessful();

    Event::assertNotDispatched(StaleStepsDetected::class, function (StaleStepsDetected $event) {
        return $event->reason === 'group_no_progress'
            && ($event->context['group'] ?? null) === 'sparse-group';
    });
});
