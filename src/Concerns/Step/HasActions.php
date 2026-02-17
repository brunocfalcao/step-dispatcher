<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\Step;

use Illuminate\Support\Str;

trait HasActions
{
    /**
     * Mark this step as a parent by setting child_block_uuid.
     * Returns the child_block_uuid to be used when creating children.
     */
    public function makeItAParent(): string
    {
        $childBlockUuid = (string) Str::uuid();
        $this->update(['child_block_uuid' => $childBlockUuid]);

        return $childBlockUuid;
    }
}
