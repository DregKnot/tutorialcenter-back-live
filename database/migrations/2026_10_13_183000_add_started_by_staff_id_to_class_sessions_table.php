<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('class_sessions', 'started_by_staff_id')) {
                $table->unsignedBigInteger('started_by_staff_id')->nullable()->after('status');
                $table->foreign('started_by_staff_id')->references('id')->on('staffs')->nullOnDelete();
            }
            if (!Schema::hasColumn('class_sessions', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('started_by_staff_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'started_by_staff_id')) {
                $table->dropForeign(['started_by_staff_id']);
                $table->dropColumn('started_by_staff_id');
            }
            if (Schema::hasColumn('class_sessions', 'started_at')) {
                $table->dropColumn('started_at');
            }
        });
    }
};
