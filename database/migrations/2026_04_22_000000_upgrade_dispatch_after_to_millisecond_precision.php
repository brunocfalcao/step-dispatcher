<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promote `dispatch_after` from second-precision `TIMESTAMP` to
     * millisecond-precision `TIMESTAMP(3)` on both the live `steps` table
     * and the historical `steps_archive` table.
     *
     * Why
     * ---
     * The throttler path (`BaseApiThrottler::canDispatch` +
     * `BaseApiableJob::shouldStartOrThrottle`) was rounding every
     * sub-second retry wait up to whole seconds because `dispatch_after`
     * could only store seconds. For APIs with tight min-delay (TAAPI
     * 200 ms, exchange throttlers 50-200 ms, CMC 2500 ms remainders
     * often in the hundreds), that rounding cost 30-80% of the
     * configured rate budget every cron cycle. Millisecond precision
     * at the column level lets retry scheduling speak the same language
     * as the throttler math.
     *
     * Safety
     * ------
     * - Uses Laravel's Schema Builder `->change()` so each driver emits
     *   the right DDL (MySQL `MODIFY`, PostgreSQL `ALTER COLUMN ... TYPE`).
     * - Widening the type preserves existing data — second-precision values
     *   stay valid with `.000` trailing.
     * - `down()` narrows back to second precision; sub-second components
     *   get truncated, which is safe because those rows are transient
     *   retry markers (not historical truth).
     */
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->timestamp('dispatch_after', 3)->nullable()->change();
        });

        Schema::table('steps_archive', function (Blueprint $table) {
            $table->timestamp('dispatch_after', 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->timestamp('dispatch_after')->nullable()->change();
        });

        Schema::table('steps_archive', function (Blueprint $table) {
            $table->timestamp('dispatch_after')->nullable()->change();
        });
    }
};
