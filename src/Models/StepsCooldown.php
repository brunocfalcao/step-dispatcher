<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use StepDispatcher\Abstracts\BaseModel;

/**
 * An active cooldown. Deliberately NOT prefix-aware: the global row
 * (`scope = '*'`) and every per-scope row live in one shared table so
 * a global cooldown is seen by every scope's dispatcher.
 *
 * @property string $scope
 * @property \Illuminate\Support\Carbon|null $cooled_at
 */
final class StepsCooldown extends BaseModel
{
    public const GLOBAL_SCOPE = '*';

    protected $table = 'steps_cooldowns';

    protected $fillable = ['scope', 'cooled_at'];

    protected $casts = [
        'cooled_at' => 'datetime',
    ];
}
