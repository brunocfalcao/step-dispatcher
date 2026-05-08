<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use StepDispatcher\Abstracts\BaseModel;
use StepDispatcher\Support\RuntimeContext;

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
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration' => 'integer',
    ];

    public static function tableName(): string
    {
        return app(RuntimeContext::class)->current().'steps_dispatcher_ticks';
    }

    /**
     * Resolve the live table name. Honours an explicit `setTable()`
     * override on the instance before falling back to the active
     * runtime prefix.
     */
    public function getTable(): string
    {
        return $this->table ?? self::tableName();
    }

    public function steps()
    {
        return $this->hasMany(Step::class, 'tick_id');
    }
}
