<?php

declare(strict_types=1);

namespace StepDispatcher\Abstracts;

use Illuminate\Database\Eloquent\Model;
use StepDispatcher\Concerns\BaseModel\HasConditionalUpdates;

abstract class BaseModel extends Model
{
    use HasConditionalUpdates;

    /**
     * Raw SQL expression for the current high-precision timestamp,
     * resolved from THIS model's own connection driver — not the
     * default connection. Centralises the one driver branch the package
     * needs for clock values so raw-SQL sites don't each reimplement it
     * (and don't silently break on a connection the default-connection
     * driver check guessed wrong).
     */
    public static function currentTimestampSql(): string
    {
        return match ((new static)->getConnection()->getDriverName()) {
            'pgsql' => 'clock_timestamp()',
            'sqlite' => "strftime('%Y-%m-%d %H:%M:%f', 'now')",
            default => 'NOW(6)', // mysql, mariadb
        };
    }

    /**
     * Quote a column identifier through THIS model's connection grammar
     * so reserved words (group, index, queue) are valid in raw SQL on
     * every driver — backticks on MySQL, double-quotes on PostgreSQL.
     */
    public static function wrapColumn(string $column): string
    {
        return (new static)->getConnection()->getQueryGrammar()->wrap($column);
    }
}
