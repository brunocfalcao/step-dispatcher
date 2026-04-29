<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use StepDispatcher\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string|null $group
 * @property int $progress
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null $duration
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class StepsDispatcherTicks extends BaseModel
{
    protected $table = 'steps_dispatcher_ticks';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration' => 'integer',
    ];

    public function steps()
    {
        return $this->hasMany(Step::class, 'tick_id');
    }
}
