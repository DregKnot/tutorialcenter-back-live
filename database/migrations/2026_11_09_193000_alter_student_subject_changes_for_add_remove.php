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
        Schema::table('student_subject_changes', function (Blueprint $table) {
            $table->string('action_type', 20)->default('swap')->after('course_enrollment_id');
            $table->unsignedBigInteger('old_subject_id')->nullable()->change();
            $table->unsignedBigInteger('new_subject_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_subject_changes', function (Blueprint $table) {
            $table->dropColumn('action_type');
            $table->unsignedBigInteger('old_subject_id')->nullable(false)->change();
            $table->unsignedBigInteger('new_subject_id')->nullable(false)->change();
        });
    }
};
