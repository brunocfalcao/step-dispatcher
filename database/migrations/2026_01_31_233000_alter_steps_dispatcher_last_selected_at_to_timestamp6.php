<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alter last_selected_at to TIMESTAMP(6) for microsecond precision.
     *
     * This is required for proper round-robin group selection. Without microsecond
     * precision, rapid consecutive selections within the same second get identical
     * timestamps, breaking the ordering in getNextGroup().
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE steps_dispatcher MODIFY last_selected_at TIMESTAMP(6) NULL DEFAULT NULL');
    }

    /**
     * Revert to standard TIMESTAMP (no fractional seconds).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE steps_dispatcher MODIFY last_selected_at TIMESTAMP NULL DEFAULT NULL');
    }
};
