<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('courses_enrollments', 'exam_track')) {
                $table->string('exam_track', 20)->nullable()->after('course_id')->index();
            }
        });

        // Safe backfill of existing records based on course_id and payment metadata
        try {
            DB::statement("UPDATE courses_enrollments SET exam_track = 'JAMB' WHERE course_id = 1 AND (exam_track IS NULL OR exam_track = '')");
            DB::statement("UPDATE courses_enrollments SET exam_track = 'WAEC' WHERE course_id = 2 AND (exam_track IS NULL OR exam_track = '')");
            DB::statement("UPDATE courses_enrollments SET exam_track = 'NECO' WHERE course_id = 3 AND (exam_track IS NULL OR exam_track = '')");
            DB::statement("UPDATE courses_enrollments SET exam_track = 'WAEC' WHERE course_id = 4 AND (exam_track IS NULL OR exam_track = '')");
        } catch (\Exception $e) {
            // Ignore backfill error if running on fresh DB
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            if (Schema::hasColumn('courses_enrollments', 'exam_track')) {
                $table->dropIndex(['exam_track']);
                $table->dropColumn('exam_track');
            }
        });
    }
};
