<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Expand status column on class_sessions to allow 'proposed' and 'rescheduled'.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `class_sessions` MODIFY COLUMN `status` ENUM('scheduled', 'completed', 'cancelled', 'recorded', 'proposed', 'rescheduled') NOT NULL DEFAULT 'scheduled'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE class_sessions DROP CONSTRAINT IF EXISTS class_sessions_status_check");
            DB::statement("ALTER TABLE class_sessions ADD CONSTRAINT class_sessions_status_check CHECK (status IN ('scheduled', 'completed', 'cancelled', 'recorded', 'proposed', 'rescheduled'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `class_sessions` MODIFY COLUMN `status` ENUM('scheduled', 'completed', 'cancelled', 'recorded') NOT NULL DEFAULT 'scheduled'");
        }
    }
};