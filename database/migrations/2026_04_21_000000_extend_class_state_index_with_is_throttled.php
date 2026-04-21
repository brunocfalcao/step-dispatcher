<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend idx_steps_class_state with is_throttled as a trailing column.
     *
     * The admin step-dispatcher dashboard polls every 5 seconds with:
     *     SELECT class, state, is_throttled, COUNT(*)
     *     FROM steps GROUP BY class, state, is_throttled
     *
     * The previous index (class, state) didn't cover the third GROUP BY
     * column, so MySQL fell back to a full-table scan + temporary table
     * for the aggregation. On a 286K-row steps table this took 3-5s per
     * call and tripped the slow-query pushover alert on every tick.
     *
     * Adding is_throttled as the trailing column enables a loose index
     * scan for the aggregation (no temp table, no filesort). The new
     * index is still a covering superset of (class, state) so callers
     * that only filter/group by those two columns (OrderObserver dedup)
     * continue to use it via the left-prefix rule.
     */
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropIndex('idx_steps_class_state');

            $table->index(
                ['class', 'state', 'is_throttled'],
                'idx_steps_class_state_throttled'
            );
        });
    }

    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropIndex('idx_steps_class_state_throttled');

            $table->index(['class', 'state'], 'idx_steps_class_state');
        });
    }
};
