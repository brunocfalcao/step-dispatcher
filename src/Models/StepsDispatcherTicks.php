<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use StepDispatcher\Abstracts\BaseModel;

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
        return $this->hasMany(Step::class);
    }
}
