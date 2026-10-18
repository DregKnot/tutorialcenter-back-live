<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Index on courses_enrollments for active student subscription lookups
        Schema::table('courses_enrollments', function (Blueprint $table) {
            $table->index(['student_id', 'status', 'end_date'], 'idx_enrollments_student_status_end');
        });

        // 2. Index on class_sessions for daily scheduled classes
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->index(['session_date', 'status', 'starts_at'], 'idx_sessions_date_status_starts');
        });

        // 3. Index on exam_attempts for student performance history
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->index(['student_id', 'submitted_at', 'status'], 'idx_attempts_student_submitted_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            $table->dropIndex('idx_enrollments_student_status_end');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_sessions_date_status_starts');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_attempts_student_submitted_status');
        });
    }
};
