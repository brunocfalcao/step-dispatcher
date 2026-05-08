<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use StepDispatcher\Abstracts\BaseModel;
use StepDispatcher\Support\RuntimeContext;

/**
 * Eloquent face of the `steps_archive` table. Created so the
 * archive command + purge command can compose the destination
 * table name through a model (or a static `tableName()` helper)
 * instead of hardcoding the literal string `'steps_archive'`,
 * which would silently bypass the runtime prefix.
 *
 * The model's column shape mirrors `steps` exactly — the archive
 * is a simple INSERT-from-SELECT copy, not a materialised view.
 * No application code currently reads through this model; it
 * exists only as the canonical source of truth for the archive
 * table name.
 */
final class StepsArchive extends BaseModel
{
    protected $guarded = [];

    public static function tableName(): string
    {
        return app(RuntimeContext::class)->current().'steps_archive';
    }

    /**
     * Resolve the live table name. Honours an explicit `setTable()`
     * override before falling back to the active runtime prefix.
     */
    public function getTable(): string
    {
        return $this->table ?? self::tableName();
    }
}
