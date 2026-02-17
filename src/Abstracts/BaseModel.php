<?php

declare(strict_types=1);

namespace StepDispatcher\Abstracts;

use Illuminate\Database\Eloquent\Model;
use StepDispatcher\Concerns\BaseModel\HasConditionalUpdates;

abstract class BaseModel extends Model
{
    use HasConditionalUpdates;
}
