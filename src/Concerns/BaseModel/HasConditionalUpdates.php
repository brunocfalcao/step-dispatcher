<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\BaseModel;

/**
 * Trait for conditional update methods on BaseModel.
 *
 * This trait provides:
 * - updateSaving() - Fill and save in one operation
 * - updateIfNotSet() - Only update if attribute is null
 */
trait HasConditionalUpdates
{
    final public function updateSaving(array $attributes, array $options = []): bool
    {
        $this->fill($attributes);

        return $this->save($options);
    }

    final public function updateIfNotSet(string $attribute, mixed $value, ?callable $callable = null): bool
    {
        if (! $this->exists || $this->getKey() === null) {
            return false;
        }

        if (is_null($this->getAttribute($attribute))) {
            $this->setAttribute($attribute, $value);

            $dirty = $this->isDirty($attribute);
            $updated = $dirty ? $this->save() : false;

            if ($dirty && $callable) {
                $callable($this);
            }

            return $updated;
        }

        return false;
    }
}
