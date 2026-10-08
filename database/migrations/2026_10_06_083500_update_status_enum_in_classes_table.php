<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Expand status column on classes to allow 'rescheduled' and 'cancelled'.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `classes` MODIFY COLUMN `status` ENUM('active', 'inactive', 'proposed', 'rescheduled', 'cancelled', 'canceled') NOT NULL DEFAULT 'active'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE classes DROP CONSTRAINT IF EXISTS classes_status_check");
            DB::statement("ALTER TABLE classes ADD CONSTRAINT classes_status_check CHECK (status IN ('active', 'inactive', 'proposed', 'rescheduled', 'cancelled', 'canceled'))");
        } else {
            // SQLite already patched via rebuild; ensure migration tracks cleanly
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `classes` MODIFY COLUMN `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
        }
    }
};