<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optimizes indexes on the steps table.
     * Drops 18 redundant single-column and overlapping composite indexes,
     * adds 2 new composites for uncovered query patterns.
     * Result: 27 indexes → 11 (plus PK).
     */
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            // Drop single-column indexes already covered as leading columns in composites.
            $table->dropIndex('steps_block_uuid_index');           // prefix of idx_steps_block_index_type_state
            $table->dropIndex('steps_type_index');                 // low cardinality, covered by composites
            $table->dropIndex('steps_state_index');                // prefix of idx_steps_state_group_dispatch_type
            $table->dropIndex('steps_relatable_type_index');       // prefix of idx_p_steps_rel_state_idx
            $table->dropIndex('steps_relatable_id_index');         // not useful alone, covered by composites
            $table->dropIndex('steps_child_block_uuid_index');     // prefix of idx_steps_child_uuid_state
            $table->dropIndex('steps_workflow_id_index');          // prefix of steps_workflow_canonical_index
            $table->dropIndex('steps_was_throttled_index');        // not used in any WHERE clause
            $table->dropIndex('steps_is_throttled_index');         // only used in admin GROUP BY (full scan)
            $table->dropIndex('steps_priority_index');             // prefix of steps_state_priority_index
            $table->dropIndex('steps_dispatch_after_index');       // prefix of idx_p_steps_dispatch_state
            $table->dropIndex('idx_steps_created_at');             // prefix of idx_p_steps_created_id

            // Drop overlapping composite indexes.
            $table->dropIndex('idx_steps_block_child_uuids');      // (block_uuid, child_block_uuid) — both covered individually
            $table->dropIndex('steps_block_uuid_index_index');     // (block_uuid, index) — prefix of idx_steps_block_index_type_state
            $table->dropIndex('steps_block_uuid_state_index');     // (block_uuid, state) — prefix of steps_block_state_type_idx
            $table->dropIndex('steps_block_uuid_type_index');      // (block_uuid, type) — covered by idx_steps_block_index_type_state
            $table->dropIndex('steps_group_state_dispatch_after_idx'); // (group, state, dispatch_after) — covered by idx_steps_state_group_dispatch_type
            $table->dropIndex('steps_type_state_index');           // (type, state) — covered by idx_steps_state_group_dispatch_type
            $table->dropIndex('steps_state_priority_index');       // (state, priority) — state covered, priority not queried via index
            $table->dropIndex('idx_p_steps_state_created');        // (state, created_at) — state covered, purge uses idx_p_steps_created_id
            $table->dropIndex('idx_p_steps_dispatch_state');       // (dispatch_after, state) — covered by idx_steps_state_group_dispatch_type
            $table->dropIndex('steps_rel_idx');                    // (relatable_type, relatable_id, created_at) — covered by idx_p_steps_rel_state_idx

            // Add new indexes for uncovered query patterns.
            $table->index(['class', 'state'], 'idx_steps_class_state');           // OrderObserver dedup + admin dashboard
            $table->index(['state', 'updated_at'], 'idx_steps_state_updated_at'); // CheckStaleDataCommand
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            // Drop new indexes.
            $table->dropIndex('idx_steps_class_state');
            $table->dropIndex('idx_steps_state_updated_at');

            // Restore dropped single-column indexes.
            $table->index(['block_uuid'], 'steps_block_uuid_index');
            $table->index(['type'], 'steps_type_index');
            $table->index(['state'], 'steps_state_index');
            $table->index(['relatable_type'], 'steps_relatable_type_index');
            $table->index(['relatable_id'], 'steps_relatable_id_index');
            $table->index(['child_block_uuid'], 'steps_child_block_uuid_index');
            $table->index(['workflow_id'], 'steps_workflow_id_index');
            $table->index(['was_throttled'], 'steps_was_throttled_index');
            $table->index(['is_throttled'], 'steps_is_throttled_index');
            $table->index(['priority'], 'steps_priority_index');
            $table->index(['dispatch_after'], 'steps_dispatch_after_index');
            $table->index(['created_at'], 'idx_steps_created_at');

            // Restore dropped composite indexes.
            $table->index(['block_uuid', 'child_block_uuid'], 'idx_steps_block_child_uuids');
            $table->index(['block_uuid', 'index'], 'steps_block_uuid_index_index');
            $table->index(['block_uuid', 'state'], 'steps_block_uuid_state_index');
            $table->index(['block_uuid', 'type'], 'steps_block_uuid_type_index');
            $table->index(['group', 'state', 'dispatch_after'], 'steps_group_state_dispatch_after_idx');
            $table->index(['type', 'state'], 'steps_type_state_index');
            $table->index(['state', 'priority'], 'steps_state_priority_index');
            $table->index(['state', 'created_at'], 'idx_p_steps_state_created');
            $table->index(['dispatch_after', 'state'], 'idx_p_steps_dispatch_state');
            $table->index(['relatable_type', 'relatable_id', 'created_at'], 'steps_rel_idx');
        });
    }
};
