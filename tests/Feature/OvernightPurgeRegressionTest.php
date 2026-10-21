<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\StepsDispatcher;

function overnightTick(string $group, Carbon $created): int
{
    return DB::table('steps_dispatcher_ticks')->insertGetId([
        'group' => $group, 'progress' => 0, 'started_at' => $created,
        'completed_at' => $created, 'duration' => 0, 'created_at' => $created, 'updated_at' => $created,
    ]);
}

it('retains recent and cutoff ticks even when an older tick has the greater id', function () {
    $this->freezeTime();
    $group = 'purge-'.bin2hex(random_bytes(6));
    $recent = overnightTick($group, now()->subDay());
    $boundary = overnightTick($group, now()->subDays(30));
    $old = overnightTick($group, now()->subDays(31));
    expect(DB::table('steps_dispatcher_ticks')->whereIn('id', [$recent, $boundary, $old])->count())->toBe(3);
    expect(Artisan::call('steps:purge', ['--days' => 30]))->toBe(0);
    expect(DB::table('steps_dispatcher_ticks')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('steps_dispatcher_ticks')->where('id', $recent)->exists())->toBeTrue()
        ->and(DB::table('steps_dispatcher_ticks')->where('id', $boundary)->exists())->toBeTrue();
});

it('evaluates all callable tick pages while deleting earlier pages', function () {
    $group = 'pages-'.bin2hex(random_bytes(6));
    $kept = overnightTick($group, now());
    $ids = [];
    for ($index = 0; $index < 2005; $index++) {
        $ids[] = overnightTick($group, now()->subDay());
    }
    expect(DB::table('steps_dispatcher_ticks')->whereIn('id', $ids)->count())->toBe(2005);
    $oldCallable = StepsDispatcher::getRecordTickWhenCallable();
    StepsDispatcher::recordTickWhen(static fn ($tick): bool => $tick->id === $kept);
    try {
        expect(Artisan::call('steps:purge', ['--ticks' => true]))->toBe(0);
        expect(DB::table('steps_dispatcher_ticks')->whereIn('id', $ids)->count())->toBe(0)
            ->and(DB::table('steps_dispatcher_ticks')->where('id', $kept)->exists())->toBeTrue();
    } finally {
        (new ReflectionProperty(StepsDispatcher::class, 'recordTickWhenCallable'))->setValue(null, $oldCallable);
    }
});
