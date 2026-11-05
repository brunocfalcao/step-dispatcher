<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for an application model a step can belong to. It has no table:
 * the relatable scopes only read its morph class and key.
 */
final class RelatableTestModel extends Model
{
    public static function withKey(int $id): self
    {
        return (new self)->forceFill(['id' => $id]);
    }
}
